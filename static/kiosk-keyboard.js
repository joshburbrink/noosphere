/**
 * kiosk-keyboard.js  -  floating virtual keyboard for touchscreen kiosk mode (#85)
 *
 * Injects a QWERTY on-screen keyboard into the page. Auto-shows when text inputs
 * are focused on touch devices. Always-visible toggle button in bottom-right corner.
 * State (open/closed) persists across page loads via sessionStorage.
 */
(function () {
  'use strict';

  // ── Layout ────────────────────────────────────────────────────────────────
  var ROWS = [
    ['1','2','3','4','5','6','7','8','9','0','-','⌫'],
    ['q','w','e','r','t','y','u','i','o','p'],
    ['a','s','d','f','g','h','j','k','l',';',"'"],
    ['⇧','z','x','c','v','b','n','m',',','.','?'],
    ['@','_','SPACE','SPACE','SPACE','SPACE','SPACE','SPACE','/','⏎'],
  ];
  var SHIFT_MAP = {
    '1':'!','2':'@','3':'#','4':'$','5':'%','6':'^','7':'&','8':'*','9':'(','0':')',
    '-':'_',';':':','\'':'"',',':'<','.':'>','/':'?','@':'~','_':'+',
  };

  var shifted   = false;
  var capsLock  = false;
  var visible   = false;
  var activeEl  = null;

  // ── Build keyboard DOM ────────────────────────────────────────────────────
  var kbd = document.createElement('div');
  kbd.id  = 'ns-vkbd';
  kbd.setAttribute('style', [
    'position:fixed',
    'bottom:0',
    'left:0',
    'right:0',
    'z-index:8999',
    'background:rgba(14,14,30,0.97)',
    'border-top:2px solid #2a2a5a',
    'padding:8px 6px 10px',
    'display:none',
    'user-select:none',
    '-webkit-user-select:none',
  ].join(';'));

  function buildKeys() {
    kbd.innerHTML = '';
    ROWS.forEach(function (row) {
      var rowEl = document.createElement('div');
      rowEl.setAttribute('style', 'display:flex;justify-content:center;gap:4px;margin-bottom:4px');
      row.forEach(function (key) {
        var btn = document.createElement('button');
        btn.type = 'button';
        var isSpecial = ['⌫','⇧','⏎','SPACE'].indexOf(key) !== -1;
        var label = key;
        var flex = '1';

        if (key === 'SPACE') {
          label = ' ';
          flex = '4';
        } else if (key === '⌫') {
          label = '⌫';
          flex = '1.5';
        } else if (key === '⇧') {
          flex = '1.5';
          label = (capsLock ? '⇪' : '⇧');
        } else if (!isSpecial) {
          // Apply shift / caps
          var ch = key;
          if (shifted || capsLock) {
            ch = SHIFT_MAP[key] || key.toUpperCase();
          }
          label = ch;
        }

        btn.textContent = label;
        btn.dataset.key = key;
        btn.setAttribute('style', [
          'flex:' + flex,
          'min-width:28px',
          'height:40px',
          'border-radius:5px',
          'border:1px solid #3a3a6a',
          'background:' + (isSpecial ? '#1a1a40' : '#0f0f26'),
          'color:' + ((key === '⇧' && (shifted || capsLock)) ? '#4a9eff' : '#ddd'),
          'font-size:14px',
          'cursor:pointer',
          'touch-action:manipulation',
          'transition:background .1s',
        ].join(';'));

        btn.addEventListener('touchstart', function (e) {
          e.preventDefault();
          handleKey(key);
          btn.style.background = '#2a2a5a';
        }, { passive: false });
        btn.addEventListener('touchend', function () {
          btn.style.background = isSpecial ? '#1a1a40' : '#0f0f26';
        }, { passive: true });
        btn.addEventListener('mousedown', function (e) {
          e.preventDefault();
          handleKey(key);
        });

        rowEl.appendChild(btn);
      });
      kbd.appendChild(rowEl);
    });
  }

  // ── Key press handler ────────────────────────────────────────────────────
  function handleKey(key) {
    if (key === '⌫') {
      deleteChar();
      return;
    }
    if (key === '⏎') {
      inject('\n');
      // Submit the form if the input is inside one and there's only one input
      if (activeEl) {
        var form = activeEl.closest ? activeEl.closest('form') : null;
        if (form) {
          var inputs = form.querySelectorAll('input[type=text],input[type=number],input[type=email],input[type=password],input[type=search]');
          if (inputs.length <= 1) form.submit();
        }
      }
      return;
    }
    if (key === '⇧') {
      if (shifted && !capsLock) {
        // Second tap = caps lock
        capsLock = true;
        shifted  = false;
      } else if (capsLock) {
        capsLock = false;
        shifted  = false;
      } else {
        shifted = true;
      }
      buildKeys();
      return;
    }
    if (key === 'SPACE') {
      inject(' ');
      return;
    }
    var ch = key;
    if (shifted || capsLock) {
      ch = SHIFT_MAP[key] || key.toUpperCase();
    }
    inject(ch);
    // Auto-unshift after one char (not caps lock)
    if (shifted && !capsLock) {
      shifted = false;
      buildKeys();
    }
  }

  function inject(ch) {
    var el = activeEl || document.activeElement;
    if (!el || !el.tagName) return;
    var tag = el.tagName.toLowerCase();
    if (tag !== 'input' && tag !== 'textarea') return;
    var start = el.selectionStart;
    var end   = el.selectionEnd;
    var val   = el.value;
    el.value  = val.substring(0, start) + ch + val.substring(end);
    el.selectionStart = el.selectionEnd = start + ch.length;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function deleteChar() {
    var el = activeEl || document.activeElement;
    if (!el) return;
    var start = el.selectionStart;
    var end   = el.selectionEnd;
    if (start !== end) {
      el.value = el.value.substring(0, start) + el.value.substring(end);
      el.selectionStart = el.selectionEnd = start;
    } else if (start > 0) {
      el.value = el.value.substring(0, start - 1) + el.value.substring(start);
      el.selectionStart = el.selectionEnd = start - 1;
    }
    el.dispatchEvent(new Event('input', { bubbles: true }));
  }

  // ── Show / hide ──────────────────────────────────────────────────────────
  function show() {
    visible = true;
    kbd.style.display = 'block';
    toggleBtn.classList.add('active');
    try { sessionStorage.setItem('ns_kiosk_kbd', '1'); } catch(e){}
  }

  function hide() {
    visible = false;
    kbd.style.display = 'none';
    toggleBtn.classList.remove('active');
    try { sessionStorage.setItem('ns_kiosk_kbd', '0'); } catch(e){}
  }

  // ── Toggle button ────────────────────────────────────────────────────────
  var toggleBtn = document.createElement('div');
  toggleBtn.id = 'ns-kbd-toggle';
  toggleBtn.title = 'Toggle keyboard';
  toggleBtn.innerHTML = '⌨';
  toggleBtn.addEventListener('touchstart', function (e) {
    e.preventDefault();
    visible ? hide() : show();
  }, { passive: false });
  toggleBtn.addEventListener('mousedown', function (e) {
    e.preventDefault();
    visible ? hide() : show();
  });

  // ── Auto-show on input focus (touch devices) ─────────────────────────────
  var TOUCH_INPUT_TYPES = ['text','number','email','password','search','tel','url'];
  document.addEventListener('focusin', function (e) {
    var el = e.target;
    if (!el) return;
    var tag  = el.tagName ? el.tagName.toLowerCase() : '';
    var type = (el.type || '').toLowerCase();
    if (tag === 'textarea' || (tag === 'input' && TOUCH_INPUT_TYPES.indexOf(type) !== -1)) {
      activeEl = el;
      show();
      // Scroll the field into view above the keyboard
      setTimeout(function () {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }, 80);
    }
  }, true);

  document.addEventListener('focusout', function (e) {
    var el = e.target;
    if (el === activeEl) {
      // Don't clear activeEl immediately — user may be tapping a key
      setTimeout(function () {
        if (document.activeElement === kbd || kbd.contains(document.activeElement)) return;
        activeEl = null;
      }, 200);
    }
  }, true);

  // ── Init ──────────────────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', function () {
    buildKeys();
    document.body.appendChild(kbd);
    document.body.appendChild(toggleBtn);

    // Restore visibility from sessionStorage
    var saved;
    try { saved = sessionStorage.getItem('ns_kiosk_kbd'); } catch(e){}
    if (saved === '1') show();
  });

})();
