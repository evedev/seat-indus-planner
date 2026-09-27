/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Structure editor: rigs filtered by structure type, solar system
 * autocompletion (security deduced from it), live bonus preview.
 */
(function () {
  'use strict';

  const cfg = window.IndusStructureEditor;
  const texts = cfg.texts || {};

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  const SECURITY_COLORS = { 'Highsec': '#44cc44', 'Lowsec': '#ccaa33', 'Null / Wormhole': '#cc4444' };

  const typeSelect = document.getElementById('structure-type');
  const systemInput = document.getElementById('system-name');
  const systemId = document.getElementById('system-id');
  const suggestions = document.getElementById('system-suggestions');
  const security = document.getElementById('security');
  const badge = document.getElementById('security-badge');
  const useDetected = document.getElementById('use-detected');
  const rigSelects = Array.from(document.querySelectorAll('.rig-select'));
  const preview = document.getElementById('bonus-preview');

  function abbrev(name) {
    let s = name.replace(/^Standup ?/, '');
    [['XL-Set ', 'XL '], ['L-Set ', 'L '], ['M-Set ', 'M '], ['Manufacturing ', 'Mfg '],
      ['Material Efficiency', 'ME'], ['Time Efficiency', 'TE'], ['Reactor ', 'Reac '], ['Efficiency', 'Eff']]
      .forEach(([a, b]) => { s = s.split(a).join(b); });
    return s.trim();
  }

  function setSecurity(cls, status) {
    if (cls) security.value = cls;
    if (cls) {
      const color = SECURITY_COLORS[cls] || '#888';
      badge.innerHTML = '<span style="color:' + color + ';font-weight:bold">● ' + cls +
        (status !== null && status !== undefined ? ' (' + Number(status).toFixed(2) + ')' : '') + '</span>';
    } else {
      badge.textContent = '';
    }
  }

  // Fills the rig lists compatible with the chosen type, keeping the current
  // selection when possible.
  function refreshRigs() {
    const rigs = cfg.rigsByType[typeSelect.value] || [];
    rigSelects.forEach((select) => {
      const current = select.value || select.dataset.current || '';
      select.innerHTML = '';
      const none = new Option('— ' + (texts.no_rig || 'No rig') + ' —', '');
      select.add(none);
      rigs.forEach((rig) => {
        const option = new Option(abbrev(rig.name) + (rig.tier === 2 ? ' ★' : ''), rig.type_id);
        option.title = rig.name;
        select.add(option);
      });
      select.value = rigs.some((r) => String(r.type_id) === String(current)) ? current : '';
      select.dataset.current = '';
    });
    refreshLock();
  }

  function refreshLock() {
    const locked = useDetected && useDetected.checked;
    rigSelects.forEach((s) => { s.disabled = locked; });
  }

  let previewTimer = null;
  function refreshPreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(() => {
      fetch(cfg.previewUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
        body: JSON.stringify({
          structure_type_id: typeSelect.value,
          security: security.value,
          rigs: rigSelects.map((s) => s.value).filter(Boolean),
        }),
      }).then((r) => r.json()).then((rows) => {
        preview.innerHTML = rows.length
          ? rows.map((r) => '<tr><td>' + escapeHtml(r.label) + '</td><td class="text-center">-' + r.me.toFixed(1) +
              ' %</td><td class="text-center">-' + r.te.toFixed(1) + ' %</td></tr>').join('')
          : '<tr><td colspan="3" class="text-muted text-center">' + escapeHtml(texts.no_bonus || 'No bonus') + '</td></tr>';
      });
    }, 150);
  }

  // Solar system autocompletion.
  let searchTimer = null;
  function searchSystems() {
    clearTimeout(searchTimer);
    systemId.value = '';
    const q = systemInput.value.trim();
    if (q.length < 2) { suggestions.innerHTML = ''; return; }
    searchTimer = setTimeout(() => {
      fetch(cfg.systemsUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
        .then((r) => r.json())
        .then((systems) => {
          suggestions.innerHTML = '';
          systems.forEach((sys) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action py-1';
            item.innerHTML = escapeHtml(sys.name) + ' <small style="color:' + (SECURITY_COLORS[sys.security_class] || '#888') + '">' +
              sys.security_class + ' (' + sys.security_status.toFixed(2) + ')</small>';
            item.addEventListener('click', () => {
              systemInput.value = sys.name;
              systemId.value = sys.id;
              suggestions.innerHTML = '';
              setSecurity(sys.security_class, sys.security_status);
              refreshPreview();
            });
            suggestions.appendChild(item);
          });
        });
    }, 200);
  }

  typeSelect.addEventListener('change', () => { refreshRigs(); refreshPreview(); });
  rigSelects.forEach((s) => s.addEventListener('change', refreshPreview));
  if (systemInput && !systemInput.disabled) systemInput.addEventListener('input', searchSystems);
  if (useDetected) useDetected.addEventListener('change', refreshLock);

  // Disabled fields are not submitted: rig fields are enabled again on
  // submit, the other ones are ignored server side.
  document.getElementById('structure-form').addEventListener('submit', () => {
    rigSelects.forEach((s) => { s.disabled = false; });
  });

  setSecurity(cfg.securityClass, cfg.securityStatus);
  refreshRigs();
  refreshPreview();
})();
