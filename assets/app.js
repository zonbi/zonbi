(function () {
  'use strict';

  var yen = function (n) { return '¥' + Math.round(n).toLocaleString('ja-JP'); };
  var toNum = function (v) {
    v = String(v || '').replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); }).replace(/[,，¥￥円\s]/g, '');
    var n = parseInt(v, 10);
    return isNaN(n) ? 0 : n;
  };
  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* ignore */ } }
  };

  // ---- 入力フォーム: 小計・合計の自動計算、行の追加・削除
  document.querySelectorAll('[data-entry-form]').forEach(function (form) {
    var rowsBox = form.querySelector('[data-rows]');
    var tpl = form.querySelector('[data-row-template]');
    var formTotal = form.querySelector('[data-form-total]');
    var nextIndex = rowsBox ? rowsBox.querySelectorAll('[data-entry-row]').length : 0;

    function recalc() {
      var total = 0, unpriced = 0;
      form.querySelectorAll('[data-entry-row]').forEach(function (row) {
        if (row.closest('template')) return;
        var q = toNum((row.querySelector('[data-qty]') || {}).value);
        var priceRaw = String((row.querySelector('[data-price]') || {}).value || '').trim();
        var out = row.querySelector('[data-row-total]');
        if (priceRaw === '') {
          // 単価未定（商品名が入っている行だけ数える）
          var name = row.querySelector('input[name$="[item_name]"], input[name="item_name"]');
          if (name && name.value.trim() !== '') unpriced++;
          if (out) out.textContent = '単価未定';
          return;
        }
        var sub = q * toNum(priceRaw);
        total += sub;
        if (out) out.textContent = yen(sub);
      });
      if (formTotal) formTotal.textContent = yen(total) + (unpriced ? '＋単価未定' + unpriced + '件' : '');
      form.querySelectorAll('[data-remove-row]').forEach(function (b) {
        b.hidden = rowsBox && rowsBox.querySelectorAll('[data-entry-row]').length <= 1;
      });
    }

    form.addEventListener('input', recalc);
    form.addEventListener('click', function (ev) {
      var rm = ev.target.closest('[data-remove-row]');
      if (rm) {
        rm.closest('[data-entry-row]').remove();
        recalc();
      }
    });

    var addBtn = form.querySelector('[data-add-row]');
    if (addBtn && tpl && rowsBox) {
      addBtn.addEventListener('click', function () {
        var html = tpl.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var row = wrap.firstElementChild;
        rowsBox.appendChild(row);
        recalc();
        var first = row.querySelector('input');
        if (first) first.focus();
      });
    }

    // 名前を記憶（次回入力を楽に）
    var nameInput = form.querySelector('[data-remember-name]');
    if (nameInput) {
      if (!nameInput.value) nameInput.value = store.get('zonbi_name', '');
      form.addEventListener('submit', function () { store.set('zonbi_name', nameInput.value.trim()); });
    }

    // 二重送信防止
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type=submit]');
      if (btn) setTimeout(function () { btn.disabled = true; }, 0);
    });

    recalc();
  });

  // ---- 確認ダイアログ
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // ---- コピー
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () {
        var old = btn.textContent;
        btn.textContent = 'コピーしました';
        setTimeout(function () { btn.textContent = old; }, 1500);
      };
      if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done, function () { fallback(text); done(); });
      } else { fallback(text); done(); }
    });
  });
  function fallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) { /* ignore */ }
    ta.remove();
  }
  document.querySelectorAll('[data-select-on-focus]').forEach(function (i) {
    i.addEventListener('focus', function () { i.select(); });
  });

  // ---- このブラウザで作ったセッション一覧（localStorage）
  var KEY = 'zonbi_recent';
  var meta = document.querySelector('[data-admin-meta]');
  var recent = store.get(KEY, []);
  if (!Array.isArray(recent)) recent = [];
  if (meta) {
    var id = meta.getAttribute('data-id');
    recent = recent.filter(function (r) { return r.id !== id; });
    recent.unshift({ id: id, title: meta.getAttribute('data-title'), url: meta.getAttribute('data-url'), at: Date.now() });
    store.set(KEY, recent.slice(0, 30));
  }
  var deleted = new URLSearchParams(location.search).get('deleted');
  if (deleted) {
    recent = recent.filter(function (r) { return r.id !== deleted; });
    store.set(KEY, recent);
  }
  var list = document.querySelector('[data-recent-list]');
  if (list && recent.length) {
    recent.forEach(function (r) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = r.url; a.textContent = r.title;
      var d = document.createElement('span');
      d.className = 'muted small';
      d.textContent = new Date(r.at).toLocaleDateString('ja-JP');
      li.appendChild(a); li.appendChild(d);
      list.appendChild(li);
    });
    document.getElementById('recent-sessions').hidden = false;
  }
})();
