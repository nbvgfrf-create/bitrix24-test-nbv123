<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (isset($_GET['work'])) {
    require __DIR__ . '/worker.php';
    exit;
}

if (isset($_GET['prepare'])) {
    require __DIR__ . '/prepare.php';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Bitrix24 XLSX Import</title>
<style>
body{font-family:Arial,sans-serif;max-width:900px;margin:30px auto;padding:0 20px}button{padding:10px 16px;margin-right:8px;cursor:pointer}pre{background:#f4f4f4;padding:15px;min-height:250px;white-space:pre-wrap}.note{margin:15px 0}
</style>
</head>
<body>
<h1>Импорт XLSX → Bitrix24</h1>
<div class="note">Сначала подготовь XLSX, затем запусти импорт. Страница сама повторяет worker, пока импорт не закончится.</div>
<button onclick="prepare()">1. Подготовить</button>
<button onclick="start()">2. Запустить импорт</button>
<pre id="log">Готово к запуску.</pre>
<script>
const log = document.getElementById('log');
let running = false;
function add(text){log.textContent += '\n' + text;}
async function prepare(){
  const r = await fetch('run.php?prepare=1&_='+Date.now());
  log.textContent = await r.text();
}
async function start(){
  if(running) return;
  running = true;
  async function tick(){
    try{
      const r = await fetch('run.php?work=1&_='+Date.now(), {cache:'no-store'});
      const text = await r.text();
      add('\n' + new Date().toLocaleString('ru-RU') + '\n' + text);
      if(!r.ok || text.includes('ОШИБКА:') || text.includes('ГОТОВО: импорт завершён.')){running=false; return;}
    }catch(e){add('Ошибка: '+e);}
    setTimeout(tick, 1000);
  }
  tick();
}
</script>
</body>
</html>
