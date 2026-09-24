import imaplib
import email
import os
import threading
import time
import re
import requests
from typing import List, Optional
from fastapi import FastAPI, File, UploadFile, Form
from fastapi.responses import HTMLResponse
from pydantic import BaseModel
import uvicorn

app = FastAPI(title="CIDB Cancellation Portal")

# ---------------------------------------------------------
# CONFIGURATION
# ---------------------------------------------------------
EMAIL_USER = "rpa-portal@yourcompany.com" 
EMAIL_PASS = "your_password"              
IMAP_SERVER = "imap.yourcompany.com"      
ATTACHMENT_DIR = "./attachments"

RPA_BASE_URL = "http://103.241.150.147:8081"
RPA_API_URL = f"{RPA_BASE_URL}/api/external/ticket-insert"
RPA_API_KEY = "NRUrvwxAMZEOckJ9vQ-Sp9KNSdRsjfGUFW3CdsM96wLrl4Qs"

os.makedirs(ATTACHMENT_DIR, exist_ok=True)

# ---------------------------------------------------------
# TEXT EXTRACTION ENGINE (REGEX + FALLBACK GUARANTEES)
# ---------------------------------------------------------
def extract_info_from_text(text: str, default_email: str = "") -> dict:
    crm_match = re.search(r'(CRM-\S+)', text)
    name_match = re.search(r'NAME:\s*(.*)', text, re.IGNORECASE)
    nric_match = re.search(r'NRIC:\s*(.*)', text, re.IGNORECASE)
    email_match = re.search(r'(?:EMAIL|E-MAIL):\s*(\S+@\S+)', text, re.IGNORECASE)
    type_match = re.search(r'(?:CUSTOMER\s*TYPE|TYPE):\s*(Company|Individual|PPK)', text, re.IGNORECASE)

    # 1. Customer Email Fallback Chain
    if email_match and email_match.group(1).strip():
        customer_email = email_match.group(1).strip()
    elif default_email and default_email.strip():
        customer_email = default_email.strip()
    else:
        raw_email = re.search(r'[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}', text)
        customer_email = raw_email.group(0).strip() if raw_email else "unknown@customer.com"

    # 2. Customer Type Enum Normalization (Valid: Company, Individual, PPK)
    raw_type = type_match.group(1).strip() if type_match else ""
    if raw_type.lower() == "ppk":
        customer_type = "PPK"
    elif raw_type.lower() == "company":
        customer_type = "Company"
    else:
        customer_type = "Individual"  # Default fallback matching backend enum requirement

    return {
        "crm_id": crm_match.group(1).strip() if crm_match else "CRM-UNKNOWN",
        "name": name_match.group(1).strip() if name_match else "UNKNOWN NAME",
        "nric": nric_match.group(1).strip() if nric_match else "UNKNOWN NRIC",
        "customer_email": customer_email,
        "customer_type": customer_type,
    }

# ---------------------------------------------------------
# 1. LIVE RPA INTEGRATION
# ---------------------------------------------------------
def trigger_rpa(extracted_data: dict, ocr_results: list):
    """Triggers RPA API using CIDB email channel schema."""
    headers = {
        "accept": "*/*",
        "X-API-Key": RPA_API_KEY,
        "Content-Type": "application/json"
    }

    # Guarantees all required fields for cidb_masterbot + email channel
    payload = {
        "company": "CIDB",
        "scenario_key": "cidb_masterbot",
        "channel": "email",
        "fields": {
            "sCRMID": extracted_data.get("crm_id") or "CRM-UNKNOWN",
            "sCustomerName": extracted_data.get("name") or "UNKNOWN NAME",
            "sCustomerEmail": extracted_data.get("customer_email") or "unknown@customer.com",
            "sNRIC": extracted_data.get("nric") or "UNKNOWN NRIC",
            "sCustomerType": extracted_data.get("customer_type") or "Individual"
        }
    }

    try:
        response = requests.post(RPA_API_URL, headers=headers, json=payload, timeout=10)
        
        if response.status_code in [200, 201]:
            return {"status": "success", "api_response": response.json()}
        else:
            return {"status": "error", "code": response.status_code, "detail": response.text}
            
    except requests.exceptions.RequestException as e:
        return {"status": "error", "detail": str(e)}

# ---------------------------------------------------------
# 2. OCR MODULE (PLACEHOLDER)
# ---------------------------------------------------------
def trigger_ocr_module(file_path: str):
    print(f"[OCR] Processing: {file_path}")
    return {"file": file_path, "status": "success", "extracted_text": "dummy_ocr_data"}

# ---------------------------------------------------------
# 3. EMAIL RECEIPT & EXTRACTION
# ---------------------------------------------------------
def check_inbox_and_extract():
    try:
        mail = imaplib.IMAP4_SSL(IMAP_SERVER)
        mail.login(EMAIL_USER, EMAIL_PASS)
        mail.select('inbox')

        status, messages = mail.search(None, 'UNSEEN')
        if not messages[0]:
            return
            
        email_ids = messages[0].split()

        for e_id in email_ids:
            res, msg_data = mail.fetch(e_id, '(RFC822)')
            raw_email = msg_data[0][1]
            msg = email.message_from_bytes(raw_email)

            raw_sender = msg.get('from', '')
            sender_email_match = re.search(r'[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}', raw_sender)
            clean_sender = sender_email_match.group(0) if sender_email_match else raw_sender

            subject = msg.get('subject', '')
            body_text = ""
            saved_attachments = []

            for part in msg.walk():
                if part.get_content_type() == "text/plain":
                    body_text = part.get_payload(decode=True).decode(errors='ignore')
                
                if part.get_content_maintype() == 'multipart' or part.get('Content-Disposition') is None:
                    continue

                filename = part.get_filename()
                if filename:
                    filepath = os.path.join(ATTACHMENT_DIR, filename)
                    with open(filepath, 'wb') as f:
                        f.write(part.get_payload(decode=True))
                    saved_attachments.append(filepath)

            print(f"\n[EMAIL DETECTED] From: {clean_sender} | Subject: {subject}")
            
            extracted_data = extract_info_from_text(body_text, default_email=clean_sender)

            ocr_results = [trigger_ocr_module(fp) for fp in saved_attachments]
            trigger_rpa(extracted_data, ocr_results)

        mail.close()
        mail.logout()
    except Exception:
        pass

def email_polling_worker():
    while True:
        check_inbox_and_extract()
        time.sleep(30)

@app.on_event("startup")
def start_background_email_listener():
    thread = threading.Thread(target=email_polling_worker, daemon=True)
    thread.start()

# ---------------------------------------------------------
# PROXY ENDPOINTS FOR CIDB MYSQL TICKETING DATA
# ---------------------------------------------------------
class StatusUpdateModel(BaseModel):
    crmid: str
    status: str

@app.get("/api/crm-logs")
async def get_all_crm_logs():
    """Proxies request to central API to fetch MySQL cidb_ticketing_data records."""
    try:
        res = requests.get(f"{RPA_BASE_URL}/api/external/cidb-tickets", timeout=10)
        return res.json()
    except Exception:
        return []

@app.post("/api/crm-update")
async def update_crm_status(payload: StatusUpdateModel):
    """Proxies status updates from RPA to central API."""
    try:
        res = requests.post(f"{RPA_BASE_URL}/api/external/cidb-status-update", json=payload.dict(), timeout=10)
        return res.json()
    except Exception as e:
        return {"status": "error", "detail": str(e)}

# ---------------------------------------------------------
# PORTAL UI WITH SIDEBAR
# ---------------------------------------------------------
@app.get("/", response_class=HTMLResponse)
async def render_portal():
    return """
    <!DOCTYPE html>
    <html>
    <head>
        <title>CIDB Automated Portal</title>
        <style>
            * { box-sizing: border-box; }
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; display: flex; height: 100vh; background-color: #f4f6f9; }
            .sidebar { width: 250px; background-color: #1e293b; color: white; display: flex; flex-direction: column; padding: 20px 0; }
            .sidebar h2 { font-size: 18px; text-align: center; margin-bottom: 30px; color: #38bdf8; }
            .tab-btn { background: none; border: none; color: #94a3b8; padding: 15px 25px; text-align: left; font-size: 15px; cursor: pointer; width: 100%; transition: 0.2s; }
            .tab-btn:hover, .tab-btn.active { background-color: #334155; color: white; border-left: 4px solid #38bdf8; }
            .main-content { flex: 1; padding: 40px; overflow-y: auto; }
            .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); max-width: 900px; }
            label { font-weight: bold; display: block; margin-top: 15px; margin-bottom: 5px; color: #334155; }
            textarea, input[type="file"], input[type="text"], button { width: 100%; padding: 10px; margin-bottom: 10px; border: 1px solid #cbd5e1; border-radius: 4px; }
            textarea { height: 140px; }
            button { background-color: #0284c7; color: white; border: none; font-size: 16px; cursor: pointer; font-weight: bold; margin-top: 15px; }
            button:hover { background-color: #0369a1; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #e2e8f0; padding: 12px; text-align: left; font-size: 14px; }
            th { background-color: #f8fafc; color: #475569; }
            .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; background: #e0f2fe; color: #0369a1; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
        </style>
    </head>
    <body>
        <div class="sidebar">
            <h2>CIDB Automation</h2>
            <button class="tab-btn active" onclick="switchTab('manual')">📝 Manual Trigger</button>
            <button class="tab-btn" onclick="switchTab('tracker')">🔍 CRM DB Tracker</button>
        </div>

        <div class="main-content">
            <!-- TAB 1: MANUAL TRIGGER -->
            <div id="manual" class="tab-content active">
                <div class="card">
                    <h2>Smart Manual Trigger</h2>
                    <p style="color: #64748b; font-size: 14px;">Paste the case details below to trigger RPA using the CIDB Email Channel schema.</p>
                    <form action="/manual-trigger" method="post" enctype="multipart/form-data">
                        <label>Paste Case Information:</label>
                        <textarea name="pasted_text" placeholder="CRM-1789370515-78&#10;NAME: ADIQ AJMAL BIN NORAZUHAN&#10;NRIC: 991031036189&#10;EMAIL: adiq@gmail.com&#10;TYPE: Individual" required></textarea>
                        
                        <label>Upload Attachments (Multiple Allowed):</label>
                        <input type="file" name="attachments" multiple required>
                        
                        <button type="submit">Submit to RPA (Email Channel)</button>
                    </form>
                </div>
            </div>

            <!-- TAB 2: CRM TRACKER -->
            <div id="tracker" class="tab-content">
                <div class="card" style="max-width: 1000px;">
                    <h2>CRM DB Tracker (MySQL: cidb_ticketing_data)</h2>
                    <p style="color: #64748b; font-size: 14px;">Live records queried directly from MySQL DB table.</p>
                    <input type="text" id="searchInput" onkeyup="filterCRMTable()" placeholder="Search CRM ID, Name, or NRIC...">
                    
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>CRM ID</th>
                                <th>Customer Name</th>
                                <th>NRIC</th>
                                <th>Status</th>
                                <th>Date Time</th>
                            </tr>
                        </thead>
                        <tbody id="crmTableBody">
                            <tr><td colspan="6" style="text-align:center;">Loading database records...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
            function switchTab(tabId) {
                document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
                document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
                document.getElementById(tabId).classList.add('active');
                event.target.classList.add('active');
                if(tabId === 'tracker') fetchCRMLogs();
            }

            async function fetchCRMLogs() {
                try {
                    const res = await fetch('/api/crm-logs');
                    const data = await res.json();
                    const tbody = document.getElementById('crmTableBody');
                    tbody.innerHTML = '';

                    if(!data || data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No records in MySQL database.</td></tr>';
                        return;
                    }

                    data.forEach(row => {
                        tbody.innerHTML += `
                            <tr>
                                <td>${row.id}</td>
                                <td><b>${row.crmid}</b></td>
                                <td>${row.customerName || 'N/A'}</td>
                                <td>${row.nric || 'N/A'}</td>
                                <td><span class="status-badge">${row.status}</span></td>
                                <td>${row.datetime}</td>
                            </tr>
                        `;
                    });
                } catch(e) { console.error(e); }
            }

            function filterCRMTable() {
                const query = document.getElementById('searchInput').value.toLowerCase();
                const rows = document.querySelectorAll('#crmTableBody tr');
                rows.forEach(row => {
                    const text = row.innerText.toLowerCase();
                    row.style.display = text.includes(query) ? '' : 'none';
                });
            }
        </script>
    </body>
    </html>
    """

@app.post("/manual-trigger")
async def manual_trigger(
    pasted_text: str = Form(...),
    attachments: List[UploadFile] = File(...)
):
    saved_filepaths = []
    ocr_results = []

    for attachment in attachments:
        filepath = os.path.join(ATTACHMENT_DIR, attachment.filename)
        with open(filepath, "wb") as f:
            f.write(await attachment.read())
        saved_filepaths.append(filepath)
        ocr_results.append(trigger_ocr_module(filepath))

    extracted_data = extract_info_from_text(pasted_text)
    rpa_result = trigger_rpa(extracted_data, ocr_results)

    return {
        "status": "Success",
        "extracted_information": {
            "CRM_ID": extracted_data["crm_id"],
            "Customer_Name": extracted_data["name"],
            "Customer_Email": extracted_data["customer_email"],
            "NRIC": extracted_data["nric"],
            "Customer_Type": extracted_data["customer_type"]
        },
        "files_saved": saved_filepaths,
        "ocr_outputs": ocr_results,
        "rpa_api_response": rpa_result
    }

if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=9000)