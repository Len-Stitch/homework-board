#!/usr/bin/env node
/*
 * 作业清单 · 班级作业共享服务
 *
 * 用法：
 *   node server.js                       # 默认端口 3000
 *   PORT=8080 CLASS_PASSWORD=1234 node server.js
 *
 * 密码只从环境变量 CLASS_PASSWORD 读取，没设置时是占位符 CHANGE_ME，
 * 此时所有需要密码的接口都会拒绝（避免默认密码被人直接登进后台）。
 *
 * 只依赖 Node 自带模块，不需要 npm install。
 * 班级作业保存在 data/class-homework.json，个人作业仍只存在各自浏览器的 localStorage。
 */
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = __dirname;
const DATA_DIR = path.join(ROOT, 'data');
const DATA_FILE = path.join(DATA_DIR, 'class-homework.json');
const NOTICE_FILE = path.join(DATA_DIR, 'notice.json');
const FEEDBACK_FILE = path.join(DATA_DIR, 'feedback.json');
const USERS_FILE = path.join(DATA_DIR, 'users.json');
// 登录态独立存一个文件：保存作业时不会把刚登录的 token 覆盖掉
const SESSIONS_FILE = path.join(DATA_DIR, 'sessions.json');
const PORT = Number(process.env.PORT) || 3000;
const PASSWORD = String(process.env.CLASS_PASSWORD || 'CHANGE_ME');
const ADMIN_READY = PASSWORD !== '' && PASSWORD !== 'CHANGE_ME';
const MAX_BODY = 256 * 1024;

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.ico': 'image/x-icon',
  '.webmanifest': 'application/manifest+json',
  '.woff2': 'font/woff2'
};

/* ---------- 数据读写 ---------- */

// 班级作业、反馈、账号、登录态都是「{ v, updatedAt, items: [] }」的形状，
// 读写的差异只有文件名，收敛成一组函数，避免四份复制粘贴
function readList(file, label) {
  try {
    const data = JSON.parse(fs.readFileSync(file, 'utf8'));
    const list = Array.isArray(data) ? data : (data && Array.isArray(data.items) ? data.items : []);
    return list.filter(x => x && typeof x === 'object');
  } catch (err) {
    if (err.code !== 'ENOENT') console.warn(`读取${label}失败：`, err.message);
    return [];
  }
}

function writeList(file, items) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  const tmp = file + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify({ v: 1, updatedAt: Date.now(), items }, null, 2), 'utf8');
  fs.renameSync(tmp, file);
}

const readItems = () => readList(DATA_FILE, '班级作业');
const writeItems = items => writeList(DATA_FILE, items);
const readFeedback = () => readList(FEEDBACK_FILE, '反馈');
const writeFeedback = list => writeList(FEEDBACK_FILE, list);
const readUsers = () => readList(USERS_FILE, '用户');
const writeUsers = list => writeList(USERS_FILE, list);
const readSessions = () => readList(SESSIONS_FILE, '登录态');
const writeSessions = list => writeList(SESSIONS_FILE, list);

/* ---------- 通知（单独一个字段，形状不一样） ---------- */

function readNotice() {
  try {
    const data = JSON.parse(fs.readFileSync(NOTICE_FILE, 'utf8'));
    return data && typeof data.text === 'string' ? data.text : '';
  } catch (err) {
    if (err.code !== 'ENOENT') console.warn('读取通知失败：', err.message);
    return '';
  }
}

function writeNotice(text) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  const tmp = NOTICE_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify({ v: 1, text, updatedAt: Date.now() }, null, 2), 'utf8');
  fs.renameSync(tmp, NOTICE_FILE);
}

function hashPassword(password) {
  const salt = crypto.randomBytes(16).toString('hex');
  const hash = crypto.scryptSync(password, salt, 64).toString('hex');
  return salt + ':' + hash;
}

function verifyPassword(password, stored) {
  if (typeof stored !== 'string' || stored.indexOf(':') < 0) return false;
  const parts = stored.split(':');
  const salt = parts[0];
  const hash = parts[1];
  let test;
  try {
    test = crypto.scryptSync(password, salt, 64).toString('hex');
  } catch (err) {
    return false;
  }
  if (test.length !== hash.length) return false;
  return crypto.timingSafeEqual(Buffer.from(test, 'hex'), Buffer.from(hash, 'hex'));
}

const newToken = () => crypto.randomBytes(16).toString('hex');

/* ---------- 登录态（独立文件） ---------- */

const MAX_SESSIONS_PER_USER = 20;

function saveSession(token, userId) {
  const clean = readSessions().filter(x => x.token !== token);
  clean.push({ token, userId, at: Date.now() });
  // 只限制这个账号自己的登录态数量，别的账号原样保留
  const mine = clean.filter(x => x.userId === userId).sort((a, b) => (b.at || 0) - (a.at || 0));
  const others = clean.filter(x => x.userId !== userId);
  writeSessions(others.concat(mine.slice(0, MAX_SESSIONS_PER_USER)));
}

function dropSession(token) {
  writeSessions(readSessions().filter(x => x.token !== token));
}

// 老版本把 tokens 存在 users.json 里，这里顺手迁移
function findUserByToken(users, token) {
  if (typeof token !== 'string' || !token) return null;
  const session = readSessions().find(x => x.token === token);
  if (session) {
    const hit = users.find(u => u.id === session.userId);
    if (hit) return hit;
  }
  for (const user of users) {
    if (!Array.isArray(user.tokens)) continue;
    if (user.tokens.indexOf(token) !== -1) {
      if (user.id) {
        try { saveSession(token, user.id); } catch (err) {}
      }
      return user;
    }
  }
  return null;
}

function userPublic(user) {
  return { id: user.id || '', name: user.name || '', createdAt: Number(user.createdAt) || 0 };
}

// 这条反馈是不是这个账号提交的
function feedbackBelongsTo(fb, user) {
  if (!fb || !user) return false;
  if (typeof fb.ownerId === 'string' && fb.ownerId) return fb.ownerId === user.id;
  return typeof fb.ownerName === 'string' && fb.ownerName !== '' && fb.ownerName === user.name;
}

/* ---------- 小工具 ---------- */

function sendJson(res, code, obj) {
  const buf = Buffer.from(JSON.stringify(obj), 'utf8');
  res.writeHead(code, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': buf.length,
    'Cache-Control': 'no-store'
  });
  res.end(buf);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', chunk => {
      size += chunk.length;
      if (size > MAX_BODY) {
        reject(new Error('内容太大'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      try {
        resolve(chunks.length ? JSON.parse(Buffer.concat(chunks).toString('utf8')) : {});
      } catch (err) {
        reject(new Error('格式不正确'));
      }
    });
    req.on('error', reject);
  });
}

function passwordOk(value) {
  // 没设置 CLASS_PASSWORD 时一律不通过，防止用公开的占位符登录后台
  if (!ADMIN_READY || typeof value !== 'string') return false;
  const given = Buffer.from(value, 'utf8');
  const expected = Buffer.from(PASSWORD, 'utf8');
  // timingSafeEqual 要求字节长度一致，先比长度再常量时间比较
  if (given.length !== expected.length) return false;
  return crypto.timingSafeEqual(given, expected);
}

// 班级作业只保留必要字段，避免前端塞进奇怪的东西
function sanitize(raw) {
  if (!raw || typeof raw !== 'object') return null;
  const id = typeof raw.id === 'string' && raw.id ? raw.id.slice(0, 64) : null;
  const subject = typeof raw.subject === 'string' ? raw.subject.slice(0, 8) : '';
  const type = typeof raw.type === 'string' ? raw.type.slice(0, 12) : '';
  const text = typeof raw.text === 'string' ? raw.text.trim().slice(0, 40) : '';
  const date = typeof raw.date === 'string' ? raw.date.slice(0, 10) : '';
  const parts = Math.max(1, Math.min(20, Number(raw.parts) || 1));
  if (!id || !subject || !type || !/^\d{4}-\d{2}-\d{2}$/.test(date)) return null;
  return { id, subject, type, text, date, parts, createdAt: Number(raw.createdAt) || Date.now() };
}

/* ---------- API ---------- */

async function handleApi(req, res, pathname) {
  if (pathname === '/api/class/homework' && req.method === 'GET') {
    return sendJson(res, 200, { ok: true, items: readItems() });
  }

  if (pathname === '/api/class/notice' && req.method === 'GET') {
    return sendJson(res, 200, { ok: true, text: readNotice() });
  }

  if (pathname === '/api/class/notice' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    if (!passwordOk(body.password)) return sendJson(res, 403, { ok: false, error: '密码不对' });
    const text = typeof body.text === 'string' ? body.text.trim().slice(0, 200) : '';
    try {
      writeNotice(text);
    } catch (err) {
      return sendJson(res, 500, { ok: false, error: '保存失败' });
    }
    return sendJson(res, 200, { ok: true, text });
  }

  if (pathname === '/api/class/verify' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    if (!ADMIN_READY) return sendJson(res, 503, { ok: false, error: '服务端还没设置 CLASS_PASSWORD 环境变量' });
    if (!passwordOk(body.password)) return sendJson(res, 403, { ok: false, error: '密码不对' });
    return sendJson(res, 200, { ok: true });
  }

  // 注册 / 登录
  if ((pathname === '/api/class/register' || pathname === '/api/class/login') && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const name = typeof body.name === 'string' ? body.name.trim().slice(0, 16) : '';
    const password = typeof body.password === 'string' ? body.password : '';
    if (name.length < 2) return sendJson(res, 400, { ok: false, error: '用户名至少 2 个字' });
    if (!/^[\u4e00-\u9fa5A-Za-z0-9_]+$/.test(name)) {
      return sendJson(res, 400, { ok: false, error: '用户名只能用中文、字母、数字、下划线' });
    }
    if (password.length < 4 || password.length > 32) {
      return sendJson(res, 400, { ok: false, error: '密码需要 4–32 位' });
    }

    const users = readUsers();
    const idx = users.findIndex(u => typeof u.name === 'string' && u.name.toLowerCase() === name.toLowerCase());
    const isRegister = pathname === '/api/class/register';

    if (isRegister) {
      if (idx >= 0) return sendJson(res, 409, { ok: false, error: '这个用户名已经被注册了' });
      const token = newToken();
      const user = {
        id: 'u-' + newToken().slice(0, 12),
        name,
        hash: hashPassword(password),
        createdAt: Date.now(),
        data: { updatedAt: 0, items: [] }
      };
      users.push(user);
      writeUsers(users);
      saveSession(token, user.id);
      return sendJson(res, 200, { ok: true, token, user: userPublic(user) });
    }

    if (idx < 0 || !verifyPassword(password, users[idx].hash)) {
      return sendJson(res, 403, { ok: false, error: '用户名或密码不对' });
    }
    // 登录只写 sessions.json，不再重写 users.json，避免多设备互相覆盖
    const token = newToken();
    saveSession(token, users[idx].id);
    return sendJson(res, 200, { ok: true, token, user: userPublic(users[idx]) });
  }

  // 登录用户：个人信息与自己的作业
  if (req.method === 'POST' && (
    pathname === '/api/class/me' ||
    pathname === '/api/class/my-data' ||
    pathname === '/api/class/my-data-save' ||
    pathname === '/api/class/logout'
  )) {
    const body = await readBody(req).catch(() => ({}));
    const users = readUsers();
    const user = findUserByToken(users, body.token);
    if (!user) return sendJson(res, 401, { ok: false, error: '登录已过期，请重新登录' });
    const idx = users.findIndex(u => u.id === user.id);

    if (pathname === '/api/class/me') {
      return sendJson(res, 200, { ok: true, user: userPublic(user) });
    }

    if (pathname === '/api/class/logout') {
      dropSession(body.token);
      return sendJson(res, 200, { ok: true });
    }

    if (pathname === '/api/class/my-data') {
      const data = user.data && Array.isArray(user.data.items) ? user.data.items : [];
      return sendJson(res, 200, { ok: true, items: data });
    }

    // my-data-save
    const rawItems = Array.isArray(body.items) ? body.items : [];
    const clean = rawItems.map(raw => {
      const item = sanitize(raw);
      if (!item) return null;
      // 个人作业要保留勾选状态
      item.done = Array.from({ length: item.parts }, (_, i) => Boolean(raw.done && raw.done[i]));
      return item;
    }).filter(Boolean).slice(-500);
    // 写之前重新读一次，避免把另一台设备刚写入的数据覆盖掉
    const fresh = readUsers();
    const target = fresh.find(u => u.id === user.id);
    if (target) target.data = { updatedAt: Date.now(), items: clean };
    writeUsers(fresh);
    return sendJson(res, 200, { ok: true, items: clean });
  }

  // 意见反馈：任何人都能提交，不用密码；登录用户会记下归属
  if (pathname === '/api/class/feedback' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    let name = typeof body.name === 'string' ? body.name.trim().slice(0, 20) : '';
    const text = typeof body.text === 'string' ? body.text.trim().slice(0, 500) : '';
    if (!text) return sendJson(res, 400, { ok: false, error: '反馈内容不能为空' });
    const user = findUserByToken(readUsers(), body.token);
    if (user && !name) name = user.name || '';
    const entry = {
      id: 'fb-' + crypto.randomUUID().slice(0, 12),
      name,
      text,
      at: Date.now()
    };
    if (user) {
      entry.ownerId = user.id || '';
      entry.ownerName = user.name || '';
    }
    const list = readFeedback();
    list.push(entry);
    try {
      writeFeedback(list.slice(-300));   // 只留最近 300 条
    } catch (err) {
      return sendJson(res, 500, { ok: false, error: '保存失败' });
    }
    return sendJson(res, 200, { ok: true });
  }

  // 反馈列表：管理员看全部，普通账号只看自己的，按时间从新到旧
  if (pathname === '/api/class/feedback-list' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const isAdmin = passwordOk(body.password);
    const user = isAdmin ? null : findUserByToken(readUsers(), body.token);
    if (!isAdmin && !user) {
      return sendJson(res, 403, { ok: false, error: '请先登录，或输入管理员密码' });
    }
    let items = readFeedback();
    if (!isAdmin) items = items.filter(fb => feedbackBelongsTo(fb, user));
    items = items.sort((a, b) => (Number(b.at) || 0) - (Number(a.at) || 0));
    return sendJson(res, 200, { ok: true, scope: isAdmin ? 'all' : 'mine', items });
  }

  // 回复某条反馈：管理员可回复任意，普通账号只能回复自己的
  if (pathname === '/api/class/feedback-reply' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const isAdmin = passwordOk(body.password);
    const user = isAdmin ? null : findUserByToken(readUsers(), body.token);
    if (!isAdmin && !user) {
      return sendJson(res, 403, { ok: false, error: '请先登录，或输入管理员密码' });
    }
    const id = typeof body.id === 'string' ? body.id : '';
    const text = typeof body.text === 'string' ? body.text.trim().slice(0, 300) : '';
    if (!id || !text) return sendJson(res, 400, { ok: false, error: '回复内容不能为空' });

    const list = readFeedback();
    const target = list.find(x => x.id === id);
    if (!target) return sendJson(res, 404, { ok: false, error: '这条反馈不存在' });
    if (!isAdmin && !feedbackBelongsTo(target, user)) {
      return sendJson(res, 403, { ok: false, error: '只能回复自己提交的反馈' });
    }
    if (!Array.isArray(target.replies)) target.replies = [];
    target.replies.push({
      id: 'r-' + newToken().slice(0, 10),
      text,
      by: isAdmin ? '管理员' : user.name,
      at: Date.now()
    });
    try {
      writeFeedback(list);
    } catch (err) {
      return sendJson(res, 500, { ok: false, error: '保存失败' });
    }
    return sendJson(res, 200, { ok: true, items: list });
  }

  if (pathname === '/api/class/homework/add' && req.method === 'POST') {
    const body = await readBody(req);
    if (!passwordOk(body.password)) return sendJson(res, 403, { ok: false, error: '密码不对' });
    const item = sanitize(body.item);
    if (!item) return sendJson(res, 400, { ok: false, error: '作业内容不完整' });
    const items = readItems();
    if (items.some(x => x.id === item.id)) return sendJson(res, 409, { ok: false, error: '这份作业已经存在' });
    items.push(item);
    writeItems(items);
    return sendJson(res, 200, { ok: true, items });
  }

  if (pathname === '/api/class/homework/delete' && req.method === 'POST') {
    const body = await readBody(req);
    if (!passwordOk(body.password)) return sendJson(res, 403, { ok: false, error: '密码不对' });
    const id = typeof body.id === 'string' ? body.id : '';
    const items = readItems().filter(x => x.id !== id);
    writeItems(items);
    return sendJson(res, 200, { ok: true, items });
  }

  return sendJson(res, 404, { ok: false, error: '接口不存在' });
}

/* ---------- 静态文件 ---------- */

function serveStatic(req, res, pathname) {
  let rel = decodeURIComponent(pathname);
  if (rel === '/' || rel === '') rel = '/index.html';
  const filePath = path.join(ROOT, rel);
  if (!filePath.startsWith(ROOT)) {
    res.writeHead(403);
    return res.end('Forbidden');
  }
  fs.stat(filePath, (err, stat) => {
    if (err || !stat.isFile()) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      return res.end('Not found');
    }
    const ext = path.extname(filePath).toLowerCase();
    const type = MIME[ext] || 'application/octet-stream';
    const noCache = ext === '.html' || ext === '.json';
    res.writeHead(200, {
      'Content-Type': type,
      'Content-Length': stat.size,
      'Cache-Control': noCache ? 'no-cache' : 'public, max-age=3600'
    });
    fs.createReadStream(filePath).pipe(res);
  });
}

/* ---------- 启动 ---------- */

const server = http.createServer(async (req, res) => {
  const pathname = new URL(req.url, 'http://localhost').pathname;
  try {
    if (pathname.startsWith('/api/')) {
      await handleApi(req, res, pathname);
    } else if (req.method === 'GET' || req.method === 'HEAD') {
      serveStatic(req, res, pathname);
    } else {
      res.writeHead(405);
      res.end('Method not allowed');
    }
  } catch (err) {
    console.error('请求出错：', err.message);
    if (!res.headersSent) sendJson(res, 500, { ok: false, error: '服务器出错了' });
    else res.end();
  }
});

server.listen(PORT, () => {
  const nets = require('os').networkInterfaces();
  const addrs = Object.values(nets).flat()
    .filter(n => n && n.family === 'IPv4' && !n.internal)
    .map(n => n.address);
  console.log('作业清单已启动');
  console.log('  本机访问：http://localhost:' + PORT);
  addrs.forEach(a => console.log('  局域网访问：http://' + a + ':' + PORT));
  console.log('  班级作业文件：' + DATA_FILE);
  console.log('  班级密码：' + (ADMIN_READY ? '已从环境变量 CLASS_PASSWORD 读取' : '未设置！请先设置 CLASS_PASSWORD 环境变量再启动'));
});
