// Real HTTP regression for login -> home when the email migration is incomplete.
// Uses an isolated app copy, in-memory PostgreSQL, and a local mock RPA endpoint.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const os=require('node:os');
const http=require('node:http');
const net=require('node:net');
const {once}=require('node:events');
const {spawn,spawnSync}=require('node:child_process');
const {createRequire}=require('node:module');
const root=path.resolve(__dirname,'..');
const dep=createRequire(path.join(root,'var/email-test-runtime/package.json'));
const {PGlite}=dep('@electric-sql/pglite');
const {pgcrypto}=dep('@electric-sql/pglite/contrib/pgcrypto');
const {PGLiteSocketServer}=dep('@electric-sql/pglite-socket');
const php=process.env.PHP_BINARY || 'php';
async function freePort(){const s=net.createServer();s.listen(0,'127.0.0.1');await once(s,'listening');const p=s.address().port;await new Promise(r=>s.close(r));return p;}

(async()=>{
 const temp=fs.mkdtempSync(path.join(os.tmpdir(),'cidb-portal-http-'));
 const db=await PGlite.create({extensions:{pgcrypto}});
 const dbPort=await freePort();
 const socket=new PGLiteSocketServer({db,host:'127.0.0.1',port:dbPort});
 let app,logs='',cookie='';const submissions=[];
 const bot=http.createServer((req,res)=>{let body='';req.on('data',b=>body+=b);req.on('end',()=>{
   submissions.push(JSON.parse(body));res.writeHead(200,{'Content-Type':'application/json'});res.end('{"status":"inserted"}');
 });});
 try {
   await db.exec(fs.readFileSync(path.join(root,'database/schema.sql'),'utf8').replace(/^\\ir .*$/gm,''));
   await db.exec("ALTER TABLE portal_requests ADD COLUMN request_source varchar(8) NOT NULL DEFAULT 'form'");
   const hashed=spawnSync(php,['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);','local-fixture-password'],{encoding:'utf8',windowsHide:true});
   if(hashed.error)throw hashed.error;
   assert.equal(hashed.status,0,hashed.stderr);
   const user=(await db.query('INSERT INTO portal_users(username,email,password_hash) VALUES ($1,$2,$3) RETURNING id',['fixture','fixture@example.test',hashed.stdout])).rows[0];
   await socket.start();bot.listen(0,'127.0.0.1');await once(bot,'listening');
   // Copy only application files, never the actual .env or existing sessions.
   for(const dir of ['src','public','views'])fs.cpSync(path.join(root,dir),path.join(temp,dir),{recursive:true});
   fs.mkdirSync(path.join(temp,'sessions'));
   fs.writeFileSync(path.join(temp,'.env'),[
     'DB_HOST=127.0.0.1',`DB_PORT=${dbPort}`,'DB_DATABASE=postgres','DB_USERNAME=postgres','DB_PASSWORD=',
     'SESSION_SECURE_COOKIE=false',`RPA_BOT_ENDPOINT=http://127.0.0.1:${bot.address().port}/mock`,'RPA_BOT_API_KEY=fixture-only','EMAIL_ENABLED=false'
   ].join('\n'));
   const port=await freePort(),origin=`http://127.0.0.1:${port}`;
   app=spawn(php,['-d',`session.save_path="${path.join(temp,'sessions').replaceAll('\\','/')}"`,'-S',`127.0.0.1:${port}`,'-t','public','public/index.php'],{cwd:temp,windowsHide:true,stdio:['ignore','pipe','pipe']});
   app.stdout.on('data',b=>logs+=b);app.stderr.on('data',b=>logs+=b);
   let started=false;
   for(let i=0;i<60;i++){try{await fetch(origin+'/login');started=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}
   assert.ok(started,'PHP server started');
   async function request(url,form){const response=await fetch(origin+url,{redirect:'manual',method:form?'POST':'GET',headers:{...(cookie?{Cookie:cookie}:{}),...(form?{'Content-Type':'application/x-www-form-urlencoded'}:{})},body:form?new URLSearchParams(form):undefined});const set=response.headers.get('set-cookie');if(set)cookie=set.split(';')[0];return {response,text:await response.text()};}
   const field=(html,name)=>{const m=html.match(new RegExp(`name="${name}" value="([^"]+)"`));assert.ok(m,`Field ${name}`);return m[1];};
   assert.equal((await request('/')).response.status,303);
   let page=await request('/login');assert.equal(page.response.status,200);
   const csrf=field(page.text,'_csrf');
   page=await request('/login',{_csrf:csrf,username:'fixture',password:'wrong'});
   assert.equal(page.response.status,200);assert.match(page.text,/not recognised/);
   page=await request('/login',{_csrf:csrf,username:'fixture',password:'local-fixture-password'});
   assert.equal(page.response.status,303);assert.equal(page.response.headers.get('location'),'/');
   page=await request('/');assert.equal(page.response.status,200);assert.match(page.text,/Submit your information/);
   const token=field(page.text,'_csrf');
   await request('/submit',{_csrf:token});page=await request('/');
   assert.equal(page.response.status,200);assert.match(page.text,/Name is required/);assert.equal(submissions.length,0);
   const key=field(page.text,'submission_key');
   const data={_csrf:token,submission_key:key,name:'Fixture Customer',id_number:'TEST001',email:'customer@example.test',location_area:'Selangor',crm:'TEST-CRM',language:'en'};
   page=await request('/submit',data);assert.equal(page.response.status,303);
   assert.equal(submissions.length,1);assert.equal(submissions[0].fields.sCRMID,'TEST-CRM');
   page=await request('/');assert.equal(page.response.status,200);assert.match(page.text,/In progress/);
   const row=(await db.query('SELECT id,status FROM portal_requests WHERE user_id=$1',[user.id])).rows[0];
   assert.equal(row.status,'processing');
   await request('/submit',data);assert.equal(submissions.length,1,'Duplicate submit remains protected');
   for(const route of ['/request-history','/request-history?source=form','/request-history?source=email',`/request-history/${row.id}`])assert.equal((await request(route)).response.status,200,route);
   page=await request(`/request-status/${row.id}`);assert.equal(JSON.parse(page.text).complete,false);
   await db.query("UPDATE portal_requests SET status='success' WHERE id=$1",[row.id]);
   page=await request(`/request-status/${row.id}`);assert.equal(JSON.parse(page.text).message,'Success');assert.equal(JSON.parse(page.text).complete,true);
   await request('/logout',{_csrf:token});assert.equal((await request(`/request-status/${row.id}`)).response.status,401);
   assert.doesNotMatch(logs,/Portal application error|Portal request polling failed|Fatal error/);
   console.log('HTTP login, post-login home, form validation/submission, duplicate protection, history, status polling and logout passed with partial email migration.');
 } catch(error){console.error(logs);throw error;}
 finally {
   if(app && app.exitCode===null){const exited=once(app,'exit');app.kill();await exited;}
   if(bot.listening)await new Promise(r=>bot.close(r));
   await socket.stop();await db.close();
   // Only remove this generated temporary app copy, never the real project.
   const resolved=fs.realpathSync(temp),parent=fs.realpathSync(os.tmpdir());
   if(path.dirname(resolved)===parent && path.basename(resolved).startsWith('cidb-portal-http-'))fs.rmSync(resolved,{recursive:true,force:true});
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
