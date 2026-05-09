(function(){
  function ready(fn){document.readyState!=='loading'?fn():document.addEventListener('DOMContentLoaded',fn)}

  function trackImpression(card){
    var ad=card.getAttribute('data-ots-ad');
    var cfg=window.otsPublic||{};
    if(!ad || !window.fetch || !cfg.ajaxUrl || !cfg.impressionNonce) return;

    var storageKey='ots_imp_'+ad;
    try{
      if(window.sessionStorage && sessionStorage.getItem(storageKey)) return;
      if(window.sessionStorage) sessionStorage.setItem(storageKey,'1');
    }catch(e){}

    var form=new FormData();
    form.append('action','ots_track_impression');
    form.append('ad_id',ad);
    form.append('nonce',cfg.impressionNonce);

    fetch(cfg.ajaxUrl,{method:'POST',body:form,credentials:'same-origin'}).catch(function(){});
  }

  ready(function(){
    document.querySelectorAll('.ots-copy').forEach(function(btn){
      btn.addEventListener('click',function(){
        var text=btn.getAttribute('data-copy');
        if(navigator.clipboard){navigator.clipboard.writeText(text).then(function(){btn.textContent='Copiado!'});}
        else{var i=document.createElement('input');i.value=text;document.body.appendChild(i);i.select();document.execCommand('copy');i.remove();btn.textContent='Copiado!';}
      });
    });

    var cards=[].slice.call(document.querySelectorAll('[data-ots-ad]'));
    if(cards.length){
      if('IntersectionObserver' in window){
        var obs=new IntersectionObserver(function(entries){
          entries.forEach(function(entry){
            if(entry.isIntersecting){
              trackImpression(entry.target);
              obs.unobserve(entry.target);
            }
          });
        },{threshold:0.35});
        cards.forEach(function(card){obs.observe(card);});
      }else{
        cards.forEach(trackImpression);
      }
    }

    var amountInput=document.querySelector('input[name="ots_amount"]');
    var priceBox=document.querySelector('.ots-price-editable');
    var estimated=document.getElementById('ots_estimated_clicks');
    if(amountInput && priceBox && estimated){
      var basePrice=parseFloat(priceBox.getAttribute('data-base-price')||'60');
      var baseClicks=parseInt(priceBox.getAttribute('data-base-clicks')||'60',10);
      function updateClicks(){
        var value=parseFloat(String(amountInput.value).replace(',','.'));
        if(!isFinite(value)){value=basePrice;}
        if(value < basePrice){
          estimated.textContent=baseClicks;
          return;
        }
        estimated.textContent=Math.max(1, Math.floor((value/basePrice)*baseClicks));
      }
      amountInput.addEventListener('input',updateClicks);
      amountInput.addEventListener('blur',function(){
        var value=parseFloat(String(amountInput.value).replace(',','.'));
        if(!isFinite(value) || value < basePrice){
          amountInput.value=basePrice.toFixed(2);
        }
        updateClicks();
      });
      updateClicks();
    }
  });
})();
