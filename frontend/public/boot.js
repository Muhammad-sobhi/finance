// Apply saved theme/lang before first paint (kept out of index.html so the CSP needs no inline scripts).
try {
  var t = localStorage.getItem('fin.theme'), l = localStorage.getItem('fin.lang')
  if (t) document.documentElement.dataset.theme = t
  if (l) { document.documentElement.lang = l; document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr' }
} catch (e) {}
