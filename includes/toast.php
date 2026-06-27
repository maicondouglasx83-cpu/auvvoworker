<?php /* Toast global — incluir antes de </body> */ ?>
<script>
(function(){
  if (window.__auvvoToast) return;
  window.__auvvoToast = true;
  function toast(msg, type) {
    type = type || 'info';
    var wrap = document.getElementById('_auvvo-toast');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = '_auvvo-toast';
      wrap.style.cssText = 'position:fixed;top:18px;right:18px;display:flex;flex-direction:column;gap:10px;z-index:99999;pointer-events:none';
      document.body.appendChild(wrap);
    }
    var C = { error:['#FEF2F2','#FCA5A5','#991B1B'], success:['#F0FDF4','#86EFAC','#166534'], info:['#EEF2FF','#C7D2FE','#1E3A8A'] };
    var c = C[type] || C.info;
    var el = document.createElement('div');
    el.style.cssText = 'background:'+c[0]+';border:1px solid '+c[1]+';color:'+c[2]+';padding:10px 14px;border-radius:12px;min-width:220px;max-width:360px;box-shadow:0 8px 20px rgba(0,0,0,.1);font-size:.875rem;font-weight:600;pointer-events:auto';
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(function(){el.style.cssText+=';opacity:0;transition:opacity .25s'},2400);
    setTimeout(function(){el.remove()},2700);
  }
  window.toast = toast;
})();
</script>
