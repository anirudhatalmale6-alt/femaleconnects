/* ==========================================================================
   Girls Connect - chat behaviour
   Plain JavaScript, no libraries. The browser asks the server for anything
   new every couple of seconds and only ever receives messages it has not
   already got, so the traffic stays tiny.
   ========================================================================== */
(function () {
  'use strict';

  var box = document.getElementById('messages');
  if (!box) return;

  var conversationId = box.dataset.conversation;
  var myId           = parseInt(box.dataset.me, 10);
  var pollEvery      = parseInt(box.dataset.poll, 10) || 2500;
  var lastId         = parseInt(box.dataset.last, 10) || 0;

  var form     = document.getElementById('composer');
  var input    = document.getElementById('composer-body');
  var sendBtn  = document.getElementById('composer-send');
  var presence = document.querySelector('[data-presence]');

  var lastDay  = lastDayOnScreen();
  var busy     = false;
  var failures = 0;

  // ------------------------------------------------------------- helpers --

  function atBottom() {
    return box.scrollHeight - box.scrollTop - box.clientHeight < 90;
  }

  function toBottom() {
    box.scrollTop = box.scrollHeight;
  }

  function lastDayOnScreen() {
    var splits = box.querySelectorAll('.day-split');
    return splits.length ? splits[splits.length - 1].dataset.day || '' : '';
  }

  function dropEmptyNote() {
    var note = box.querySelector('[data-empty-note]');
    if (note) note.remove();
  }

  function addDaySplit(day, label) {
    var el = document.createElement('div');
    el.className = 'day-split';
    el.dataset.day = day;
    el.textContent = label;
    box.appendChild(el);
    lastDay = day;
  }

  function addMessage(m) {
    if (document.querySelector('.msg[data-id="' + m.id + '"]')) return;

    dropEmptyNote();
    if (m.day && m.day !== lastDay) addDaySplit(m.day, m.day_label);

    var mine = m.sender_id === myId;

    var wrap = document.createElement('div');
    wrap.className = 'msg ' + (mine ? 'msg--out' : 'msg--in');
    wrap.dataset.id = m.id;

    var bubble = document.createElement('div');
    bubble.className = 'msg__bubble';
    bubble.textContent = m.body;          // textContent, so nothing can inject markup

    var meta = document.createElement('div');
    meta.className = 'msg__meta';

    var time = document.createElement('span');
    time.textContent = m.time;
    meta.appendChild(time);

    if (mine) {
      var tick = document.createElement('span');
      tick.className = 'msg__tick';
      tick.setAttribute('data-tick', '');
      tick.textContent = m.read ? 'Read' : 'Sent';
      meta.appendChild(tick);
    }

    wrap.appendChild(bubble);
    wrap.appendChild(meta);
    box.appendChild(wrap);

    if (m.id > lastId) lastId = m.id;
  }

  /** Mark every one of my bubbles up to readUpTo as read. */
  function applyReadReceipts(readUpTo) {
    if (!readUpTo) return;
    var mine = box.querySelectorAll('.msg--out');
    for (var i = 0; i < mine.length; i++) {
      if (parseInt(mine[i].dataset.id, 10) <= readUpTo) {
        var tick = mine[i].querySelector('[data-tick]');
        if (tick && tick.textContent !== 'Read') tick.textContent = 'Read';
      }
    }
  }

  // ---------------------------------------------------------------- poll --

  function poll() {
    if (busy || document.hidden) return;
    busy = true;

    fetch('api/messages.php?c=' + encodeURIComponent(conversationId) + '&after=' + lastId, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) {
        if (!r.ok) throw new Error('http ' + r.status);
        return r.json();
      })
      .then(function (data) {
        failures = 0;
        if (!data || !data.ok) return;

        var stick = atBottom();
        (data.messages || []).forEach(addMessage);
        applyReadReceipts(data.read_up_to);

        if (presence && data.partner) presence.textContent = data.partner.presence;
        if (data.messages && data.messages.length && stick) toBottom();
      })
      .catch(function () {
        failures++;                       // after a run of failures, back off
      })
      .then(function () {
        busy = false;
      });
  }

  // ---------------------------------------------------------------- send --

  function send() {
    var body = input.value.trim();
    if (!body) return;

    sendBtn.disabled = true;
    var payload = new FormData(form);
    payload.set('body', body);

    fetch('api/send.php', {
      method: 'POST',
      body: payload,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          input.value = '';
          input.style.height = 'auto';
          addMessage(data.message);
          toBottom();
        } else {
          alert((data && data.error) || 'Your message could not be sent. Please try again.');
        }
      })
      .catch(function () {
        alert('Your message could not be sent. Please check your connection and try again.');
      })
      .then(function () {
        sendBtn.disabled = false;
        input.focus();
      });
  }

  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      send();
    });

    // Enter sends, Shift+Enter starts a new line.
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        send();
      }
    });

    // Grow the box as she types, up to the CSS max-height.
    input.addEventListener('input', function () {
      input.style.height = 'auto';
      input.style.height = Math.min(input.scrollHeight, 150) + 'px';
    });
  }

  // --------------------------------------------------------------- start --

  toBottom();
  if (input) input.focus();

  setInterval(function () {
    // Slow right down if the server has been unreachable for a while.
    if (failures > 5 && (Date.now() / 1000 | 0) % 6 !== 0) return;
    poll();
  }, pollEvery);

  // Catch up straight away when she comes back to the tab.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) poll();
  });
})();
