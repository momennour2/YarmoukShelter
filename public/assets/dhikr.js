/* Religious reminders: a small card appears every few minutes and fades away by itself. */
(function () {
  var me = document.currentScript;
  var every = Math.max(30, parseInt((me && me.dataset.every) || '120', 10)) * 1000;
  var stay = Math.max(5, parseInt((me && me.dataset.stay) || '12', 10)) * 1000;
  var L = [
    '﴿أَلَا بِذِكْرِ اللَّهِ تَطْمَئِنُّ الْقُلُوبُ﴾',
    '﴿فَاذْكُرُونِي أَذْكُرْكُمْ﴾',
    '﴿إِنَّ مَعَ الْعُسْرِ يُسْرًا﴾',
    '﴿وَمَن يَتَوَكَّلْ عَلَى اللَّهِ فَهُوَ حَسْبُهُ﴾',
    '﴿وَقُل رَّبِّ زِدْنِي عِلْمًا﴾',
    'سُبْحَانَ اللَّهِ وَبِحَمْدِهِ، سُبْحَانَ اللَّهِ الْعَظِيمِ',
    'لَا حَوْلَ وَلَا قُوَّةَ إِلَّا بِاللَّهِ',
    'أَسْتَغْفِرُ اللَّهَ الْعَظِيمَ وَأَتُوبُ إِلَيْهِ',
    'اللَّهُمَّ صَلِّ وَسَلِّمْ عَلَى نَبِيِّنَا مُحَمَّدٍ',
    'حَسْبُنَا اللَّهُ وَنِعْمَ الْوَكِيلُ',
    'رَبِّ اشْرَحْ لِي صَدْرِي وَيَسِّرْ لِي أَمْرِي',
    'لَا إِلَهَ إِلَّا اللَّهُ وَحْدَهُ لَا شَرِيكَ لَهُ، لَهُ الْمُلْكُ وَلَهُ الْحَمْدُ وَهُوَ عَلَى كُلِّ شَيْءٍ قَدِيرٌ',
    'قال ﷺ: «الدَّالُّ عَلَى الْخَيْرِ كَفَاعِلِهِ»',
    'قال ﷺ: «مَنْ لَا يَرْحَمْ لَا يُرْحَمْ»',
    'قال ﷺ: «وَاللَّهُ فِي عَوْنِ الْعَبْدِ مَا كَانَ الْعَبْدُ فِي عَوْنِ أَخِيهِ»'
  ];
  var last = -1, box = null;
  function pick() { var i; do { i = Math.floor(Math.random() * L.length); } while (i === last && L.length > 1); last = i; return L[i]; }
  function hide() { if (box) { box.classList.remove('on'); var b = box; setTimeout(function () { if (b.parentNode) b.parentNode.removeChild(b); }, 700); box = null; } }
  function show() {
    if (document.hidden) return;
    hide();
    box = document.createElement('div');
    box.className = 'dhikr-toast';
    box.setAttribute('role', 'status');
    var t = document.createElement('span'); t.textContent = pick();
    var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'إغلاق'); x.textContent = '×'; x.onclick = hide;
    box.appendChild(t); box.appendChild(x); document.body.appendChild(box);
    requestAnimationFrame(function () { box && box.classList.add('on'); });
    setTimeout(hide, stay);
  }
  setTimeout(show, 15000);
  setInterval(show, every);
})();
