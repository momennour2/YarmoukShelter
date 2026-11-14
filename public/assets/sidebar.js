/* Collapsible sidebar: arrow button pins it open/closed; when closed it peeks on hover and hides when the mouse leaves. */
(function () {
  'use strict';
  var sb = document.querySelector('.sidebar');
  if (!sb) return;
  var root = document.documentElement, KEY = 'ys_sb', hideT = null;
  var btn = document.createElement('button');
  btn.type = 'button'; btn.className = 'sb-toggle';
  btn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var edge = document.createElement('div'); edge.className = 'sb-edge';
  document.body.appendChild(edge); document.body.appendChild(btn);

  function collapsed() { return root.classList.contains('sb-collapsed'); }
  function sync() {
    root.style.setProperty('--sbw', sb.offsetWidth + 'px');
    var c = collapsed();
    btn.setAttribute('aria-label', c ? 'إظهار القائمة' : 'إخفاء القائمة');
    btn.title = c ? 'إظهار القائمة' : 'إخفاء القائمة';
    btn.setAttribute('aria-expanded', c ? 'false' : 'true');
  }
  function save(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }
  function peek() { if (!collapsed()) return; clearTimeout(hideT); sb.classList.add('peek'); }
  function unpeek() { clearTimeout(hideT); hideT = setTimeout(function () { sb.classList.remove('peek'); }, 250); }

  btn.addEventListener('click', function () {
    sb.classList.remove('peek');
    root.classList.toggle('sb-collapsed');
    save(collapsed() ? 'collapsed' : 'open');
    sync();
  });
  [edge, btn].forEach(function (el) { el.addEventListener('mouseenter', peek); el.addEventListener('mouseleave', unpeek); });
  sb.addEventListener('mouseenter', function () { clearTimeout(hideT); });
  sb.addEventListener('mouseleave', unpeek);
  sb.addEventListener('focusin', peek);
  window.addEventListener('resize', sync);
  sync();
  requestAnimationFrame(function () { root.classList.add('sb-ready'); });
})();
