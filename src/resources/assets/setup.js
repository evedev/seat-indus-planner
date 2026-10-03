/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Industry setup: column filters (funnel in each header), sorting, column
 * visibility (right click on a header) and scroll area of the structure
 * table, check / uncheck all buttons of the asset sources, help bubbles,
 * save buttons turned orange while changes are not saved.
 */
(function () {
  'use strict';

  const VISIBLE_ROWS = 12;

  function initStructures(table) {
    const scroll = table.closest('.indus-structures-scroll');
    const rows = Array.from(table.tBodies[0].rows);
    const counter = document.getElementById('indus-structures-count');
    const cell = (row, col) => row.querySelector('[data-col="' + col + '"]');
    const texts = JSON.parse(table.dataset.texts);
    const NO_VALUE = '—';
    const byLabel = (a, b) => (a[0] === NO_VALUE) - (b[0] === NO_VALUE) || a[1].localeCompare(b[1], undefined, { numeric: true });

    // Values of a row for a filter: the three rig slots form a single list.
    const rowValues = (row, col) => {
      if (col === 'use') return [row.querySelector('input[name="structures[]"]').checked ? 'used' : 'unused'];
      if (col === 'rig') {
        const rigs = ['rig1', 'rig2', 'rig3'].map((c) => cell(row, c).dataset.filter).filter((v) => v !== NO_VALUE);
        return rigs.length ? rigs : [NO_VALUE];
      }
      return [cell(row, col).dataset.filter];
    };

    // One filter per column; the three rig headers share the same one.
    // "list": checkboxes, none checked means no filter, a row matches when
    // one of its values is checked. "max": numeric upper bound. "text": part
    // of the name, from TEXT_MIN characters on.
    const TEXT_MIN = 3;
    const filters = new Map();
    table.querySelectorAll('.indus-filter-btn').forEach((button) => {
      const col = button.dataset.filterCol;
      if (!filters.has(col)) {
        filters.set(col, { col, kind: button.dataset.filterKind, selected: new Set(), max: '', text: '', buttons: [], options: null });
      }
      const filter = filters.get(col);
      filter.buttons.push(button);
      if (button.dataset.options) filter.options = JSON.parse(button.dataset.options);
    });
    filters.forEach((filter) => {
      if (filter.kind !== 'list' || filter.options) return;
      const values = new Set(rows.flatMap((row) => rowValues(row, filter.col)));
      filter.options = Array.from(values, (v) => [v, v === NO_VALUE && filter.col === 'rig' ? texts.no_rig : v]).sort(byLabel);
    });

    const isActive = (filter) => {
      if (filter.kind === 'max') return filter.max !== '';
      if (filter.kind === 'text') return filter.text.trim().length >= TEXT_MIN;
      return filter.selected.size > 0;
    };
    const reset = (filter) => { filter.selected = new Set(); filter.max = ''; filter.text = ''; };
    const summary = (filter) => {
      let value = texts.all;
      if (isActive(filter)) {
        if (filter.kind === 'max') value = '≤ ' + filter.max;
        else if (filter.kind === 'text') value = '"' + filter.text.trim() + '"';
        else value = filter.options.filter(([v]) => filter.selected.has(v)).map(([, l]) => l).join(', ');
      }
      return texts.summary.replace(':value', value);
    };
    const matches = (row, filter) => {
      if (!isActive(filter)) return true;
      if (filter.kind === 'max') return Number(cell(row, filter.col).dataset.value) <= Number(filter.max);
      if (filter.kind === 'text') return cell(row, filter.col).dataset.filter.toLowerCase().includes(filter.text.trim().toLowerCase());
      return rowValues(row, filter.col).some((v) => filter.selected.has(v));
    };

    // Header checkbox: checks or unchecks the rows shown (a filter narrows
    // its reach); checked when they all are, indeterminate when some are.
    const master = table.querySelector('.indus-check-all');
    const boxOf = (row) => row.querySelector('input[name="structures[]"]');
    const syncMaster = () => {
      const shownRows = rows.filter((row) => !row.hidden);
      const checked = shownRows.filter((row) => boxOf(row).checked).length;
      master.checked = shownRows.length > 0 && checked === shownRows.length;
      master.indeterminate = checked > 0 && checked < shownRows.length;
    };
    master.addEventListener('click', (e) => e.stopPropagation());
    master.addEventListener('change', () => {
      rows.filter((row) => !row.hidden).forEach((row) => { boxOf(row).checked = master.checked; });
      updateCounter();
    });

    // Checked structures (hidden ones included), plus the visible count when
    // a filter shortens the list.
    let lastShown = rows.length;
    const updateCounter = (shown = lastShown) => {
      lastShown = shown;
      syncMaster();
      const selected = rows.filter((row) => row.querySelector('input[name="structures[]"]').checked).length;
      let text = counter.dataset.template.replace(':selected', selected).replace(':total', rows.length);
      if (shown < rows.length) text += ' · ' + counter.dataset.filtered.replace(':shown', shown);
      counter.textContent = text;
    };
    table.tBodies[0].addEventListener('change', (e) => {
      if (e.target.name === 'structures[]') updateCounter();
    });

    // Hidden rows keep their checkbox: the selection is saved whole.
    const apply = () => {
      let shown = 0;
      rows.forEach((row) => {
        const visible = Array.from(filters.values()).every((filter) => matches(row, filter));
        row.hidden = !visible;
        if (visible) row.classList.toggle('odd', shown++ % 2 === 0);
      });
      updateCounter(shown);
      filters.forEach((filter) => filter.buttons.forEach((button) => {
        button.classList.toggle('active', isActive(filter));
        button.title = summary(filter);
      }));
    };

    // A single popup open at a time, attached to the page (not to the table)
    // so that the scroll area does not clip it.
    let menu = null;
    const closeMenu = () => {
      if (!menu) return;
      if (menu.owner) menu.owner.classList.remove('open');
      menu.remove();
      menu = null;
    };
    const popup = (owner) => {
      closeMenu();
      menu = document.createElement('div');
      menu.className = 'dropdown-menu show indus-ms-menu';
      menu.owner = owner;
      if (owner) owner.classList.add('open');
      return menu;
    };
    const place = (x, y) => {
      document.body.appendChild(menu);
      menu.style.top = Math.max(8, Math.min(y, window.innerHeight - menu.offsetHeight - 8)) + 'px';
      menu.style.left = Math.max(8, Math.min(x, window.innerWidth - menu.offsetWidth - 8)) + 'px';
    };
    const smallButton = (text, icon, onClick) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn-xs btn-default';
      if (icon) b.innerHTML = '<i class="' + icon + '"></i> ';
      b.append(text);
      b.addEventListener('click', onClick);
      return b;
    };
    const checkItem = (text, checked, onChange) => {
      const item = document.createElement('label');
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.checked = checked;
      box.addEventListener('change', () => onChange(box.checked));
      item.append(box, text);
      return item;
    };

    const openFilter = (filter, button) => {
      const m = popup(button);
      const r = button.getBoundingClientRect();
      if (filter.kind === 'text') {
        const line = document.createElement('div');
        line.className = 'indus-ms-max';
        const input = document.createElement('input');
        input.type = 'search';
        input.className = 'form-control form-control-sm indus-ms-text';
        input.placeholder = texts.text_hint;
        input.value = filter.text;
        input.addEventListener('input', () => { filter.text = input.value; apply(); });
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); closeMenu(); } });
        line.append(input, smallButton(texts.clear, 'fas fa-times', () => { input.value = ''; filter.text = ''; apply(); input.focus(); }));
        m.appendChild(line);
        place(r.left, r.bottom + 4);
        input.focus();
        return;
      }
      if (filter.kind === 'max') {
        const line = document.createElement('div');
        line.className = 'indus-ms-max';
        const input = document.createElement('input');
        input.type = 'number';
        input.min = '0';
        input.step = '0.01';
        input.className = 'form-control form-control-sm';
        input.placeholder = texts.max;
        input.value = filter.max;
        input.addEventListener('input', () => { filter.max = input.value; apply(); });
        line.append('≤', input, smallButton(texts.clear, 'fas fa-times', () => { input.value = ''; filter.max = ''; apply(); }));
        m.appendChild(line);
        place(r.left, r.bottom + 4);
        input.focus();
        return;
      }
      const tools = document.createElement('div');
      tools.className = 'indus-ms-tools';
      const setAll = (checked) => {
        filter.selected = new Set(checked ? filter.options.map(([v]) => v) : []);
        m.querySelectorAll('label input').forEach((box) => { box.checked = checked; });
        apply();
      };
      tools.append(smallButton(texts.check_all, 'fas fa-check-square', () => setAll(true)),
        smallButton(texts.uncheck_all, 'far fa-square', () => setAll(false)));
      m.appendChild(tools);
      filter.options.forEach(([value, text]) => m.appendChild(checkItem(text, filter.selected.has(value), (checked) => {
        if (checked) filter.selected.add(value); else filter.selected.delete(value);
        apply();
      })));
      place(r.left, r.bottom + 4);
    };

    filters.forEach((filter) => filter.buttons.forEach((button) => button.addEventListener('click', (e) => {
      e.stopPropagation();
      if (menu && menu.owner === button) closeMenu(); else openFilter(filter, button);
    })));
    document.addEventListener('mousedown', (e) => {
      if (menu && !menu.contains(e.target) && !(menu.owner && menu.owner.contains(e.target))) closeMenu();
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMenu(); });
    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', (e) => { if (menu && !menu.contains(e.target)) closeMenu(); }, true);

    // Column visibility: right click on a header opens a checklist. The
    // choice is a per-browser convenience; hiding a column drops its filter.
    const HIDDEN_KEY = 'indus-planner:setup:hidden-columns';
    const columns = JSON.parse(table.dataset.columns);
    let hidden;
    try { hidden = new Set(JSON.parse(localStorage.getItem(HIDDEN_KEY) || '[]')); } catch (e) { hidden = new Set(); }
    const columnStyle = document.createElement('style');
    document.head.appendChild(columnStyle);
    // Column groups (location, costs, rigs, origin): a tint per group and a
    // separator before the first visible column of each group.
    const GROUPS = [['loc', ['security', 'system', 'constellation', 'region']], ['cost', ['sci', 'tax']], ['rig', ['rigs']], ['end', ['origin']]];
    GROUPS.forEach(([group, keys]) => keys.forEach((key) => {
      table.querySelectorAll('[data-column="' + key + '"]').forEach((el) => el.classList.add('grp-' + group));
    }));
    const markGroupStarts = () => {
      table.querySelectorAll('.grp-start').forEach((el) => el.classList.remove('grp-start'));
      GROUPS.forEach(([, keys]) => {
        const first = keys.find((key) => !hidden.has(key));
        if (!first) return;
        // Only the first cell of each row: the three rig slots share a key.
        table.querySelectorAll('tr').forEach((tr) => {
          const cell = tr.querySelector('[data-column="' + first + '"]');
          if (cell) cell.classList.add('grp-start');
        });
      });
    };
    const renderColumns = () => {
      columnStyle.textContent = Array.from(hidden, (key) => '.indus-structures [data-column="' + key + '"] { display: none; }').join('\n');
      markGroupStarts();
    };
    const setColumn = (key, visible) => {
      if (visible) {
        hidden.delete(key);
      } else {
        hidden.add(key);
        filters.forEach((filter) => {
          if (filter.buttons.some((b) => b.closest('[data-column]') && b.closest('[data-column]').dataset.column === key)) reset(filter);
        });
      }
      try { localStorage.setItem(HIDDEN_KEY, JSON.stringify(Array.from(hidden))); } catch (e) { /* storage unavailable */ }
      renderColumns();
      apply();
      fit();
    };
    table.tHead.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      const m = popup(null);
      const title = document.createElement('h6');
      title.className = 'dropdown-header';
      title.textContent = texts.columns;
      m.appendChild(title);
      columns.forEach(([key, text]) => m.appendChild(checkItem(text, !hidden.has(key), (checked) => setColumn(key, checked))));
      place(e.clientX, e.clientY);
    });
    renderColumns();

    // Sorting: a click on a header sorts ascending, a second one descending.
    // Missing values ("—", no number) always come last.
    const sortKey = (row, col) => {
      if (col === 'use') return row.querySelector('input[name="structures[]"]').checked ? 0 : 1;
      const td = cell(row, col);
      if (td.dataset.value !== undefined) return td.dataset.value === '' ? null : Number(td.dataset.value);
      return td.dataset.filter === NO_VALUE ? null : td.dataset.filter;
    };
    const compare = (a, b) => {
      if (a === null || b === null) return (a === null) - (b === null);
      return typeof a === 'number' ? a - b : a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
    };
    const headers = Array.from(table.querySelectorAll('[data-sort-col]'));
    headers.forEach((th) => th.addEventListener('click', () => {
      const col = th.dataset.sortCol;
      const direction = th.classList.contains('sort-asc') ? -1 : 1;
      headers.forEach((h) => h.classList.remove('sort-asc', 'sort-desc'));
      th.classList.add(direction === 1 ? 'sort-asc' : 'sort-desc');
      const keys = new Map(rows.map((row) => [row, sortKey(row, col)]));
      rows.sort((a, b) => {
        const ka = keys.get(a); const kb = keys.get(b);
        if (ka === null || kb === null) return compare(ka, kb);
        return direction * compare(ka, kb);
      });
      table.tBodies[0].append(...rows);
      apply();
    }));

    // Height of the header plus VISIBLE_ROWS rows, measured on the page so
    // that it follows the theme and the font size; the border and the
    // horizontal scrollbar, when the table is wider than the page, come on top.
    const fit = () => {
      const row = rows.find((r) => !r.hidden) || rows[0];
      const chrome = scroll.offsetHeight - scroll.clientHeight;
      scroll.style.maxHeight = (table.tHead.offsetHeight + row.offsetHeight * VISIBLE_ROWS + chrome) + 'px';
    };

    apply();
    fit();
    window.addEventListener('resize', fit);
    // Fonts and icons loaded later change the row height and the table width.
    window.addEventListener('load', fit);
    if (document.fonts) document.fonts.ready.then(fit);
  }

  function initCheckButtons() {
    document.querySelectorAll('[data-check]').forEach((button) => {
      button.addEventListener('click', () => {
        const checked = button.dataset.check === 'all';
        button.closest('[data-check-group]').querySelectorAll('input[type="checkbox"]:not(:disabled)')
          .forEach((box) => { box.checked = checked; });
        const form = button.closest('form');
        if (form && form.refreshDirty) form.refreshDirty();
      });
    });
  }

  // Compares the checkboxes with their state when the page was loaded:
  // going back to it turns the button blue again.
  function initDirtyForms() {
    document.querySelectorAll('form[data-dirty-watch]').forEach((form) => {
      const button = form.querySelector('[data-save-button]');
      if (!button) return;
      const boxes = Array.from(form.querySelectorAll('input[type="checkbox"][name]'));
      const initial = boxes.map((box) => box.defaultChecked);
      form.refreshDirty = () => {
        const dirty = boxes.some((box, i) => box.checked !== initial[i]);
        button.classList.toggle('btn-warning', dirty);
        button.classList.toggle('btn-primary', !dirty);
      };
      form.addEventListener('change', form.refreshDirty);
      form.refreshDirty();
    });
  }

  // Price market form: structures known by SeAT, searched by name.
  function initMarketSearch(input) {
    const list = document.getElementById('indus-market-suggestions');
    const hidden = document.getElementById('indus-market-structure');
    const add = document.getElementById('indus-market-add');
    let timer = null;
    input.addEventListener('input', () => {
      hidden.value = '';
      add.disabled = true;
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 3) { list.innerHTML = ''; return; }
      timer = setTimeout(() => {
        fetch(input.dataset.url + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
          .then((r) => r.json())
          .then((rows) => {
            list.innerHTML = '';
            rows.forEach((row) => {
              const b = document.createElement('button');
              b.type = 'button';
              b.className = 'list-group-item list-group-item-action py-1';
              b.textContent = row.name + (row.system ? ' (' + row.system + ')' : '');
              b.addEventListener('click', () => {
                input.value = row.name;
                hidden.value = row.id;
                add.disabled = false;
                list.innerHTML = '';
              });
              list.appendChild(b);
            });
          });
      }, 250);
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('#indus-market-suggestions, #indus-market-search')) list.innerHTML = ''; });
  }

  const table = document.querySelector('.indus-structures');
  if (table) initStructures(table);
  const marketSearch = document.getElementById('indus-market-search');
  if (marketSearch) initMarketSearch(marketSearch);
  initDirtyForms();
  initCheckButtons();
  if (window.jQuery && window.jQuery.fn.tooltip) {
    window.jQuery('.indus-help').tooltip({
      template: '<div class="tooltip indus-help-tooltip" role="tooltip"><div class="arrow"></div><div class="tooltip-inner"></div></div>',
    });
  }
})();
