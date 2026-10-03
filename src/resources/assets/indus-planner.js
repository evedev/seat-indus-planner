/*
 * This file is part of seat-indus-planner.
 * Copyright (C) 2026 EveDev
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Indus Planner: user interface of the Reactions and Production tools.
 *
 * The server computes the production tree; the browser shows it as columns
 * of cards and handles the buy/produce switch, the totals, the summary and
 * the production plan. Texts come from the SeAT language files
 * (window.IndusPlannerLang, see partials/script.blade.php).
 */
(function () {
  'use strict';

  // ===================================================================
  // Translation and formatting
  // ===================================================================

  const LANG = window.IndusPlannerLang || { locale: 'en', texts: {} };
  const LOCALE = LANG.locale || 'en';
  const DASH = '—';
  const BOUGHT_MARK = '/';
  const NBSP = ' ';

  // Translated text; `:name` placeholders are replaced by `params.name`.
  function t(key, params) {
    let text = key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), LANG.texts);
    if (typeof text !== 'string') text = key;
    Object.keys(params || {}).forEach((name) => { text = text.split(':' + name).join(String(params[name])); });
    return text;
  }

  const numberFormats = {};
  function formatNumber(value, decimals) {
    decimals = decimals || 0;
    numberFormats[decimals] ??= new Intl.NumberFormat(LOCALE, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    return numberFormats[decimals].format(Number(value) || 0);
  }

  // One hover bubble for the whole page (tree cards, summary tiles), placed
  // next to the cursor and kept inside the window.
  let bubble = null;
  function showBubble(html, e) {
    if (!bubble) {
      bubble = document.createElement('div');
      bubble.className = 'indus-tip';
      document.body.appendChild(bubble);
    }
    bubble.innerHTML = html;
    bubble.style.display = 'block';
    moveBubble(e);
  }

  function bubbleShown() {
    return !!bubble && bubble.style.display === 'block';
  }

  function moveBubble(e) {
    if (!bubbleShown()) return;
    const gap = 16;
    let x = e.clientX + gap;
    let y = e.clientY + gap;
    if (x + bubble.offsetWidth > window.innerWidth - 8) x = e.clientX - gap - bubble.offsetWidth;
    if (y + bubble.offsetHeight > window.innerHeight - 8) y = e.clientY - gap - bubble.offsetHeight;
    bubble.style.left = Math.max(8, x) + 'px';
    bubble.style.top = Math.max(8, y) + 'px';
  }

  function hideBubble() {
    if (bubble) bubble.style.display = 'none';
  }

  // Pieces of a bubble: header (title, main figure), section (optional
  // caption), label / value row (optional color dot and sign class).
  const tipHead = (title, sub) => '<div class="indus-tip-head"><div><div class="indus-tip-name">' + escapeHtml(title) + '</div>' +
    (sub ? '<div class="indus-tip-sub">' + escapeHtml(sub) + '</div>' : '') + '</div></div>';
  const tipSection = (caption, body) => '<div class="indus-tip-sec">' +
    (caption ? '<div class="indus-tip-label">' + escapeHtml(caption) + '</div>' : '') + body + '</div>';
  const tipRow = (label, value, options = {}) => '<div class="indus-tip-row' + (options.sign ? ' ' + options.sign : '') + '"><span>' +
    (options.dot ? '<span class="indus-dot ' + options.dot + '"></span>' : '') + escapeHtml(label) + '</span><b>' + escapeHtml(value) + '</b></div>';
  // Amounts of the summary bubbles: millions of ISK, space as thousands separator.
  const tipIsk = (value) => (value ? spacedMillions(value) : DASH);
  const tipNote = (text, muted) => '<div class="indus-tip-note' + (muted ? ' muted' : '') + '">' + escapeHtml(text) + '</div>';

  // Short name of the price market of a payload or a plan; "Jita" for the
  // plans saved before the market choice existed.
  function marketName(data) {
    return (data && data.market && data.market.short) || 'Jita';
  }

  function formatIsk(value) {
    return value ? formatNumber(value) + ' ISK' : DASH;
  }

  // Short amount: one decimal, dropped when it is zero.
  function shortNumber(value, decimals) {
    const rounded = Number((Number(value) || 0).toFixed(decimals));
    return formatNumber(rounded, Number.isInteger(rounded) ? 0 : decimals);
  }

  function formatIskShort(value) {
    if (!value) return DASH;
    if (value >= 1e9) return shortNumber(value / 1e9, 1) + ' ' + t('unit_billion') + ' ISK';
    const millions = value / 1e6;
    if (millions < 0.05) return '< ' + formatNumber(0.1, 1) + ' ' + t('unit_million') + ' ISK';
    return shortNumber(millions, millions >= 100 ? 0 : 1) + ' ' + t('unit_million') + ' ISK';
  }

  function formatDuration(seconds) {
    seconds = Math.max(0, Math.floor(seconds || 0));
    const days = Math.floor(seconds / 86400);
    let remainder = seconds % 86400;
    const hours = Math.floor(remainder / 3600);
    remainder %= 3600;
    const minutes = Math.floor(remainder / 60);
    const secs = remainder % 60;
    const pad = (n) => String(n).padStart(2, '0');
    const hms = pad(hours) + ':' + pad(minutes) + ':' + pad(secs);
    return days ? days + t('unit_day') + ' ' + hms : hms;
  }

  // Shortened quantity for the fixed-width tree cards.
  function compact(value) {
    value = Number(value) || 0;
    if (value >= 1e9) return formatNumber(value / 1e9, 1) + NBSP + t('unit_billion');
    if (value >= 1e6) return formatNumber(value / 1e6, 1) + NBSP + t('unit_million');
    return formatNumber(value);
  }

  // Amount in millions of ISK for the rank pills: "104.4 M", "0.76 M" below one million.
  function formatMillions(value) {
    const v = Number(value) || 0;
    if (!v) return DASH;
    const m = v / 1e6;
    return formatNumber(m, Math.abs(m) < 1 ? 2 : 1) + NBSP + t('unit_million');
  }

  // Shortened amount for the summary tiles: "337.1 M", "2.3 B", "45.6 k".
  function formatShort(value) {
    const v = Number(value) || 0;
    if (!v) return DASH;
    const sign = v < 0 ? '−' : '';
    const a = Math.abs(v);
    if (a >= 1e9) return sign + formatNumber(a / 1e9, 1) + NBSP + t('unit_billion');
    if (a >= 1e6) return sign + formatNumber(a / 1e6, 1) + NBSP + t('unit_million');
    if (a >= 1e3) return sign + formatNumber(a / 1e3, 1) + NBSP + t('unit_thousand');
    return sign + formatNumber(a);
  }

  // Margin optimisation, on a tree where everything is produced: for every
  // producible node, from the deepest rank up, compares buying its need
  // (market sell price) with producing it (job cost plus its materials,
  // each at its own best cost). Nodes are merged by rank as on the cards.
  // Returns the mode of every node it could evaluate.
  function optimizeModes(payload) {
    const tree = payload.tree;
    const subItems = tree.sub_items || {};
    const price = (tid) => (payload.prices[tid] || {}).sell || 0;
    const nodes = [];
    nodes[1] = new Map(tree.rank1.map((item) => [item.type_id, item]));
    let level = new Map([...nodes[1]].filter(([, item]) => item.is_reaction_output));
    for (let rank = 2; level.size && rank <= MAX_RANK; rank++) {
      const merged = new Map();
      level.forEach((parent, parentTid) => (subItems[String(parentTid)] || []).forEach((child) => {
        const known = merged.get(child.type_id);
        merged.set(child.type_id, known
          ? Object.assign({}, known, { qty_total: known.qty_total + child.qty_total, job_cost: (known.job_cost || 0) + (child.job_cost || 0) })
          : child);
      }));
      nodes[rank] = merged;
      level = new Map([...merged].filter(([, item]) => item.is_reaction_output));
    }

    const modes = new Map();
    const memo = new Map();
    const best = (rank, tid) => {
      const key = rank + ':' + tid;
      if (memo.has(key)) return memo.get(key);
      const item = nodes[rank].get(tid);
      const unit = price(tid);
      const buy = unit > 0 ? unit * item.qty_total : Infinity;
      const children = subItems[String(tid)] || [];
      let cost = Number.isFinite(buy) ? buy : 0;
      if (item.is_reaction_output && children.length && nodes[rank + 1]) {
        let produce = item.job_cost || 0;
        children.forEach((child) => {
          const node = nodes[rank + 1].get(child.type_id);
          if (node && node.qty_total > 0) produce += best(rank + 1, child.type_id) / node.qty_total * child.qty_total;
        });
        const buyIt = buy < produce;
        modes.set(key, buyIt ? MODE_BUY : MODE_PRODUCE);
        cost = buyIt ? buy : produce;
      }
      memo.set(key, cost);
      return cost;
    };
    nodes[1].forEach((item, tid) => best(1, tid));

    return modes;
  }

  // Runs split into jobs of at most `perJob` runs (one blueprint copy per
  // job): "3×40+22" on the cards, "3 × 40 runs + 22 runs" in full.
  function jobBatches(runs, perJob) {
    if (!(runs > 0)) return null;
    const per = perJob > 0 ? Math.min(perJob, runs) : runs;
    return { full: Math.floor(runs / per), per, rest: runs % per };
  }

  function jobsText(runs, perJob, full) {
    const b = jobBatches(runs, perJob);
    if (!b) return DASH;
    if (!full) return formatNumber(b.full) + '×' + compact(b.per) + (b.rest ? '+' + compact(b.rest) : '');
    return t('jobs_split_full', { count: formatNumber(b.full), runs: formatNumber(b.per) }) +
      (b.rest ? ' + ' + t('jobs_split_rest', { runs: formatNumber(b.rest) }) : '');
  }

  // Amount in millions with a space as thousands separator, whatever the
  // language: "12.40 M", "1 234.57 M" ("12,40 M" in French).
  function spacedMillions(value) {
    const parts = new Intl.NumberFormat(LOCALE, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).formatToParts(value / 1e6);
    return parts.map((part) => (part.type === 'group' ? NBSP : part.value)).join('') + NBSP + t('unit_million');
  }

  function pct(value) {
    return (value >= 0 ? '+' : '') + formatNumber(value, 1) + ' %';
  }

  function formatDateTime(iso) {
    return new Date(iso).toLocaleString(LOCALE, { dateStyle: 'short', timeStyle: 'short' });
  }

  function escapeHtml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function icon(typeId, size) {
    return 'https://images.evetech.net/types/' + typeId + '/icon?size=' + (size || 64);
  }

  // ===================================================================
  // Colors
  // ===================================================================

  const GROUP_COLORS = {
    18: ['#7a6040', '#5a4020'], 280: ['#505870', '#303050'], 284: ['#b05030', '#803020'],
    427: ['#805090', '#603070'], 428: ['#306090', '#1a3a60'], 429: ['#308090', '#1a5060'],
    526: ['#707030', '#505020'], 711: ['#308040', '#1a5020'], 712: ['#906030', '#603010'],
    974: ['#309090', '#1a6060'], 1042: ['#408040', '#206020'], 1136: ['#306080', '#1a3a50'],
    4096: ['#904090', '#602060'], 4915: ['#508090', '#305060'], 4932: ['#806040', '#503020'],
  };
  const UNKNOWN_PALETTE = [
    ['#2a5080', '#1a3060'], ['#503828', '#302010'], ['#205840', '#103020'], ['#502038', '#301018'],
    ['#403870', '#201850'], ['#2a5858', '#1a3838'], ['#505828', '#303010'], ['#304070', '#1a2050'],
    ['#582038', '#381018'], ['#285060', '#183040'], ['#486828', '#283810'], ['#604828', '#402810'],
    ['#286068', '#183840'], ['#682058', '#401830'], ['#406840', '#204820'], ['#683828', '#403010'],
  ];

  // Knuth multiplicative hash: two neighbouring groups get clearly
  // different shades.
  function groupColor(groupId) {
    if (GROUP_COLORS[groupId]) return GROUP_COLORS[groupId];
    const hash = Number((BigInt(groupId) * 2654435761n) & 0xFFFFFFFFn);
    return UNKNOWN_PALETTE[hash % UNKNOWN_PALETTE.length];
  }

  const LINK_COLOR_REACTION = '#2ab4c4';
  const LINK_COLOR_MANUFACTURING = '#e08020';

  // Tree node modes, as exchanged with the server.
  const MODE_PRODUCE = 'P';
  const MODE_BUY = 'B';

  function modeLetter(mode) {
    return mode === MODE_BUY ? t('mode_buy_short') : t('mode_produce_short');
  }

  // ===================================================================
  // Owned blueprints
  // ===================================================================

  // `state`: bpo, bpc or missing; `icon`: Font Awesome icon (provided by SeAT).
  function blueprintLabel(bp) {
    if (!bp) return null;
    if (bp.bpo) return { cls: 'ok', state: 'bpo', icon: 'fas fa-file-alt', text: 'BPO ME' + bp.bpo.me + '/TE' + bp.bpo.te + (bp.bpo.count > 1 ? ' ×' + bp.bpo.count : '') };
    if (bp.bpc) return { cls: 'ok', state: 'bpc', icon: 'fas fa-copy', text: 'BPC ME' + bp.bpc.me + '/TE' + bp.bpc.te + ' · ' + formatNumber(bp.bpc.runs) + ' runs' };
    return { cls: 'missing', state: 'missing', icon: 'fas fa-times', text: bp.kind === 'reaction' ? t('formula_missing') : t('bp_missing') };
  }

  function blueprintIcon(bp) {
    return '<span class="indus-bp-icon ' + bp.state + '" title="' + escapeHtml(bp.text) + '"><i class="' + bp.icon + '"></i></span>';
  }

  // ===================================================================
  // Tree
  // ===================================================================

  const MAX_RANK = 6;

  class Tree {
    constructor(container, linkColor, callbacks) {
      this.container = container;
      this.linkColor = linkColor;
      this.onChange = callbacks.onChange;
      this.onStructure = callbacks.onStructure;
      this.onModeChange = callbacks.onModeChange || null;
      // Saved plan: frozen tree (no switch, no menu) and the progress of
      // every card given by `progressFor(card)`.
      this.readOnly = !!callbacks.readOnly;
      this.progressFor = callbacks.progressFor || null;
      this.columns = [];
      this.payload = null;
      window.addEventListener('resize', () => this.drawLinks());
      document.addEventListener('click', () => this.closeMenu());
      $(document).on('shown.bs.tab', () => this.drawLinks());
      // Escape releases a pinned highlight.
      document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && this.pinned) this.unpin(); });
    }

    clear(message) {
      this.hideTip();
      this.columns = [];
      this.payload = null;
      this.pool = new Map();
      this.svg = null;
      this.hovered = null;
      this.pinned = null;
      this.container.innerHTML = message ? '<div class="text-muted p-3">' + escapeHtml(message) + '</div>' : '';
    }

    // Rebuilds the tree structure but REUSES the cards already shown (same
    // rank, same item): they are updated in place, without reloading their
    // icon, so the tree does not flicker. Buy/produce choices made by the
    // user are kept as long as the final product does not change; modes set
    // automatically (item that cannot be produced, excluded reaction) are
    // never remembered.
    render(payload) {
      this.hideTip();
      this.payload = payload;
      const tree = payload.tree;

      this.previousPool = this.pool || new Map();
      if (!this.userModes) this.userModes = new Map();
      this.pool = new Map();
      this.columns = [];
      this.columnEls = [];
      if (!this.svg) {
        this.svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        this.svg.classList.add('indus-links');
      }

      const initiallyBuy = new Set(tree.rank1_initially_buy || []);

      // Column 0: final product.
      const outCard = this.makeCard(tree.output, true, 0, MODE_PRODUCE);
      this.addColumn(t('column_products'), [outCard], true);

      // Column 1: rank 1.
      const rank1Cards = [];
      const currentLevel = new Map();
      tree.rank1.forEach((item) => {
        const isSub = item.is_reaction_output;
        const card = this.makeCard(item, !isSub, 1, (!isSub || initiallyBuy.has(item.type_id)) ? MODE_BUY : MODE_PRODUCE);
        card.parents = [outCard];
        outCard.children.push(card);
        rank1Cards.push(card);
        currentLevel.set(item.type_id, card);
      });
      this.addColumn(t('column_rank', { rank: 1 }), rank1Cards);

      // Ranks 2+: the needs for the same item coming from different parents
      // are grouped on a single card.
      let level = currentLevel;
      let rank = 2;
      while (level.size && rank <= MAX_RANK) {
        const merged = new Map();
        const parentsOf = new Map();
        level.forEach((parentCard, parentTid) => {
          (tree.sub_items[String(parentTid)] || []).forEach((child) => {
            const tid = child.type_id;
            if (merged.has(tid)) {
              const ex = merged.get(tid);
              merged.set(tid, Object.assign({}, ex, {
                qty_total: ex.qty_total + child.qty_total,
                qty_base: ex.qty_base + child.qty_base,
                qty_actual: ex.qty_actual + child.qty_actual,
                job_cost: ex.job_cost + child.job_cost,
                runs: ex.runs + child.runs,
                qty_produced: ex.qty_produced + child.qty_produced,
                surplus: ex.surplus + child.surplus,
              }));
              if (!parentsOf.get(tid).includes(parentCard)) parentsOf.get(tid).push(parentCard);
            } else {
              merged.set(tid, child);
              parentsOf.set(tid, [parentCard]);
            }
          });
        });
        if (!merged.size) break;

        const cards = [];
        const next = new Map();
        merged.forEach((item, tid) => {
          const isSub = item.is_reaction_output;
          const card = this.makeCard(item, !isSub, rank, isSub ? MODE_PRODUCE : MODE_BUY);
          card.parents = parentsOf.get(tid);
          card.parents.forEach((p) => p.children.push(card));
          cards.push(card);
          if (item.is_reaction_output) next.set(tid, card);
        });
        this.addColumn(t('column_rank', { rank }), cards);
        level = next;
        rank++;
      }

      // Initial visibility: a rank 2+ card only shows when a parent produces.
      this.columns.forEach((col, i) => {
        if (i < 2) return;
        col.cards.forEach((card) => {
          card.active = card.parents.some((p) => p.active && p.mode === MODE_PRODUCE);
        });
      });

      // Content replaced in a single operation: reused cards are moved, not
      // recreated.
      this.container.replaceChildren(this.svg, ...this.columnEls);
      this.columns.forEach((col) => col.cards.forEach((card) => this.paintCard(card)));
      this.refreshTotals();
      // Cards were rebuilt: restart from the pinned card (same key) if it is
      // still shown, otherwise from the card under the mouse.
      const pinnedEl = this.pinned ? this.pool.get(this.pinned) : null;
      if (this.pinned && !(pinnedEl && pinnedEl._card.active)) this.pinned = null;
      const over = this.container.querySelector('.indus-card:hover');
      this.hovered = this.pinned ? pinnedEl._card : (over ? over._card : null);
      this.drawLinks();
      if (!this.hovered) this.highlight(null);
      this.previousPool = null;
    }

    // Card skeleton: created once, then updated.
    createCardElement() {
      const el = document.createElement('div');
      el.className = 'indus-card';
      el.innerHTML =
        '<div class="indus-card-icon"><img alt="" loading="lazy"></div>' +
        '<div class="indus-card-body"><div class="indus-card-head"><div class="indus-card-name"></div><span class="indus-card-time"></span></div>' +
        '<div class="indus-card-line"></div></div>' +
        '<div class="indus-card-side"><div class="indus-card-mode"></div><span class="indus-card-bp-slot"></span></div>' +
        '<div class="indus-card-progress"></div>';
      el.querySelector('.indus-card-mode').addEventListener('click', (e) => {
        const card = el._card;
        if (!card || card.locked || this.readOnly) return;
        e.stopPropagation();
        this.setMode(card, card.mode === MODE_PRODUCE ? MODE_BUY : MODE_PRODUCE);
      });
      // Hover: highlights the card chain (ancestors and descendants).
      // Click: pins the highlight; another click on the same card goes back
      // to the normal display (a click on another card pins that one).
      el.addEventListener('mouseenter', (e) => {
        if (!this.pinned) this.highlight(el._card);
        this.showTip(el._card, e);
      });
      // A re-render (mode switch) hides the bubble: it comes back, updated,
      // on the next move.
      el.addEventListener('mousemove', (e) => {
        if (bubbleShown()) this.moveTip(e); else this.showTip(el._card, e);
      });
      el.addEventListener('mouseleave', () => {
        if (!this.pinned) this.highlight(null);
        this.hideTip();
      });
      el.addEventListener('click', () => {
        const card = el._card;
        if (!card || !card.active) return;
        if (this.pinned === card.key) {
          this.unpin();
        } else {
          this.pinned = card.key;
          this.highlight(card);
        }
      });
      el.addEventListener('contextmenu', (e) => {
        this.hideTip();
        const card = el._card;
        if (!card || card.locked || this.readOnly) return;
        e.preventDefault();
        e.stopPropagation();
        this.openMenu(card, e.clientX, e.clientY);
      });
      return el;
    }

    makeCard(item, locked, rank, defaultMode) {
      const key = rank + ':' + item.type_id;
      const remembered = locked ? undefined : this.userModes.get(key);
      const card = {
        key, rank, item, locked, mode: remembered || defaultMode, active: true, parents: [], children: [], el: null,
      };
      const el = this.previousPool.get(key) || this.createCardElement();
      this.pool.set(key, el);
      el._card = card;

      const colors = groupColor(item.group_id);
      el.style.background = colors[0];
      el.style.borderColor = colors[1];

      // Compact card (icon height + 4 px): name on the first line, the four
      // quantities on the second; on the right, the mode badge at the top
      // and the blueprint state at the bottom.
      const produced = item.runs > 0;
      const bp = produced ? blueprintLabel(this.payload.blueprints[item.type_id]) : null;
      const img = el.querySelector('img');
      const src = icon(item.type_id, 64);
      if (img.getAttribute('src') !== src) img.setAttribute('src', src);

      const name = el.querySelector('.indus-card-name');
      name.textContent = item.name;
      name.classList.toggle('producible', !!item.is_reaction_output);
      el.querySelector('.indus-card-mode').classList.toggle('locked', locked);
      el.querySelector('.indus-card-bp-slot').innerHTML = bp ? blueprintIcon(bp) : '';
      card.bp = bp;
      el.removeAttribute('title');

      card.el = el;
      return card;
    }

    // Hover bubble of a card: header (icon, name, need and production),
    // stock gauge, total prices, then production place and logistics. A
    // bought card shows no production figures, like its quantities line.
    tooltipHtml(card) {
      const item = card.item;
      const p = this.payload.prices[item.type_id] || {};
      const qty = item.qty_total;
      const producing = item.runs > 0 && card.mode !== MODE_BUY;
      const isk = (v) => (v ? spacedMillions(v) : t('not_available'));
      const market = marketName(this.payload);
      const row = (label, value, fallback) => '<div class="indus-tip-row"><span>' + escapeHtml(label) + '</span><b>' + escapeHtml(value) +
        (fallback ? ' <span class="indus-tip-fallback">Jita</span>' : '') + '</b></div>';
      const summary = producing
        ? t('tip_summary_produced', { need: formatNumber(qty), jobs: jobsText(item.runs, item.runs_per_job, true), produced: formatNumber(item.qty_produced || item.qty_total + item.surplus) })
        : t('tip_summary_need', { need: formatNumber(qty) });

      let html = '<div class="indus-tip-head"><img src="' + icon(item.type_id, 64) + '" width="32" height="32" alt="">' +
        '<div><div class="indus-tip-name">' + escapeHtml(item.name) + '</div><div class="indus-tip-sub">' + escapeHtml(summary) + '</div></div></div>';

      if (this.payload.stock_known) {
        const stock = this.payload.stock[item.type_id] || 0;
        const ratio = qty > 0 ? Math.min(1, stock / qty) : 1;
        html += '<div class="indus-tip-sec"><div class="indus-tip-label">' +
          escapeHtml(t('tip_stock', { qty: formatNumber(stock), need: formatNumber(qty) })) + '</div>' +
          '<div class="indus-tip-bar' + (ratio >= 1 ? ' full' : '') + '"><i style="width:' + Math.round(ratio * 100) + '%"></i></div></div>';
      }

      html += '<div class="indus-tip-sec"><div class="indus-tip-label">' + escapeHtml(t('tip_prices')) + '</div>' +
        row(t('tip_buy', { market }), isk((p.buy || 0) * qty), p.buy_fallback) +
        row(t('tip_sell', { market }), isk((p.sell || 0) * qty), p.sell_fallback) +
        row(t('tip_average'), isk((p.average || 0) * qty)) +
        row(t('tip_adjusted'), isk((p.adjusted || 0) * qty)) + '</div>';

      let details = '';
      if (producing) {
        details += row(t('tip_surplus'), formatNumber(item.surplus));
        if (item.structure_name) details += row(t('tip_structure'), item.structure_name);
        if (item.time_seconds) {
          const b = jobBatches(item.runs, item.runs_per_job);
          details += row(t('tip_job_duration', { runs: formatNumber(b.per) }), formatDuration(item.time_seconds / item.runs * b.per));
          details += row(t('tip_duration'), formatDuration(item.time_seconds));
        }
      }
      const volume = (item.volume || 0) * qty;
      details += row(t('tip_volume'), volume ? formatNumber(volume, 2) + ' m³' : t('not_available'));
      if (card.bp) details += row(t('tip_blueprint'), card.bp.text);
      if (p.buy_fallback || p.sell_fallback) details += '<div class="indus-tip-note">' + escapeHtml(t('price_fallback', { market })) + '</div>';
      return html + '<div class="indus-tip-sec">' + details + '</div>';
    }

    showTip(card, e) {
      if (!card || !card.active || !this.payload) return;
      showBubble(this.tooltipHtml(card), e);
    }

    moveTip(e) {
      moveBubble(e);
    }

    hideTip() {
      hideBubble();
    }

    // Groups the cards of a column by item family (hence by color): produced
    // families first, then bought materials; by name inside a family.
    groupCards(cards) {
      const groups = new Map();
      cards.forEach((card) => {
        const id = card.item.group_id;
        if (!groups.has(id)) {
          groups.set(id, { id, name: (this.payload.groups || {})[id] || t('other_group'), cards: [], produced: false });
        }
        const group = groups.get(id);
        group.cards.push(card);
        if (card.item.is_reaction_output) group.produced = true;
      });
      const ordered = Array.from(groups.values());
      ordered.sort((a, b) => (a.produced === b.produced ? a.name.localeCompare(b.name) : (a.produced ? -1 : 1)));
      ordered.forEach((g) => g.cards.sort((a, b) => a.item.name.localeCompare(b.item.name)));
      return ordered;
    }

    addColumn(title, cards, isProducts) {
      const colEl = document.createElement('div');
      colEl.className = 'indus-column';
      colEl.innerHTML = '<div class="indus-column-title">' + escapeHtml(title) + '</div><div class="indus-column-cards"></div>';
      const cardsEl = colEl.querySelector('.indus-column-cards');
      if (isProducts) {
        cards.forEach((c) => cardsEl.appendChild(c.el));
      } else {
        const ordered = [];
        this.groupCards(cards).forEach((group) => {
          const groupEl = document.createElement('div');
          groupEl.className = 'indus-card-group';
          groupEl.innerHTML = '<div class="indus-card-group-title">' + escapeHtml(group.name) + '</div>';
          group.cards.forEach((c) => { groupEl.appendChild(c.el); c.groupEl = groupEl; ordered.push(c); });
          cardsEl.appendChild(groupEl);
        });
        cards = ordered;
      }

      // One-line totals pill: purchases (Jita sell) and run cost; the exact
      // figures, Jita buy included, are in the tooltip.
      const totals = document.createElement('div');
      totals.className = 'indus-column-totals';
      totals.innerHTML =
        (isProducts ? '' : '<span><span class="indus-dot purchases"></span>' + escapeHtml(t('purchases')) + ' <b data-t="sell">—</b></span>') +
        '<span><span class="indus-dot jobs"></span>' + escapeHtml(t('card_runs')) + ' <b data-t="runs">—</b></span>';
      colEl.appendChild(totals);
      this.columnEls.push(colEl);
      this.columns.push({ el: colEl, cards, totals, isProducts, buy: 0, sell: 0, runs: 0 });
    }

    // Quantities line: a bought card keeps its need only, the production
    // figures are replaced by BOUGHT_MARK.
    renderLine(card) {
      const item = card.item;
      const produced = item.runs > 0;
      const bought = card.mode === MODE_BUY;
      const value = (v) => '<b>' + v + '</b>';
      const figure = (show, v) => value(bought ? BOUGHT_MARK : (show ? v : DASH));
      // Duration of one full job, next to the name; none for a bought card.
      const perRun = produced ? item.time_seconds / item.runs : 0;
      const batches = jobBatches(item.runs, item.runs_per_job);
      const time = card.el.querySelector('.indus-card-time');
      const showTime = !bought && perRun > 0 && batches;
      time.innerHTML = showTime ? '<i class="far fa-clock"></i> ' + escapeHtml(formatDuration(perRun * batches.per)) : '';
      time.title = showTime ? t('job_duration_help', { runs: formatNumber(batches.per) }) : '';
      card.el.querySelector('.indus-card-line').innerHTML =
        escapeHtml(t('card_needed')) + ' ' + value(compact(item.qty_total)) +
        ' · ' + escapeHtml(t('card_produced')) + ' ' + figure(produced, compact(item.qty_produced || item.qty_total + item.surplus)) +
        ' · ' + escapeHtml(t('card_runs')) + ' ' + figure(produced, jobsText(item.runs, item.runs_per_job)) +
        ' · ' + escapeHtml(t('card_surplus')) + ' ' + figure(produced && item.surplus, compact(item.surplus));
    }

    paintCard(card) {
      card.el.style.display = card.active ? '' : 'none';
      this.renderLine(card);
      // A family whose cards are all hidden disappears too.
      if (card.groupEl) {
        const visible = Array.from(card.groupEl.querySelectorAll('.indus-card')).some((el) => el.style.display !== 'none');
        card.groupEl.style.display = visible ? '' : 'none';
      }
      card.el.classList.toggle('mode-buy', card.mode === MODE_BUY);
      const badge = card.el.querySelector('.indus-card-mode');
      badge.textContent = modeLetter(card.mode);
      badge.classList.toggle('buy', card.mode === MODE_BUY);
      this.applyProgress(card);
    }

    // Progress of a saved plan card: bar at the bottom, text at the end of
    // the line, check mark instead of the mode badge once done.
    applyProgress(card) {
      if (!this.progressFor) return;
      const pr = this.progressFor(card);
      const el = card.el;
      const done = !!(pr && pr.done);
      el.classList.toggle('done', done);
      const bar = el.querySelector('.indus-card-progress');
      bar.style.width = pr ? Math.round(Math.max(0, Math.min(1, pr.ratio)) * 100) + '%' : '0';
      bar.classList.toggle('done', done);
      const line = el.querySelector('.indus-card-line');
      const old = line.querySelector('.indus-card-track');
      if (old) old.remove();
      if (pr && pr.text) line.insertAdjacentHTML('beforeend', '<span class="indus-card-track"> · ' + escapeHtml(pr.text) + '</span>');
      const badge = el.querySelector('.indus-card-mode');
      if (done) {
        badge.innerHTML = '<i class="fas fa-check"></i>';
        badge.classList.add('done');
      } else {
        badge.classList.remove('done');
        badge.textContent = modeLetter(card.mode);
      }
    }

    refreshProgress() {
      this.columns.forEach((col) => col.cards.forEach((card) => this.applyProgress(card)));
    }

    // Only called by a user action (badge or menu).
    setMode(card, mode) {
      if (card.mode === mode) return;
      card.mode = mode;
      this.userModes.set(card.key, mode);
      this.paintCard(card);
      this.propagate(card);
      this.refreshTotals();
      this.drawLinks();
      // The quantities of the shared materials depend on the modes: the
      // server recomputes them, the local update above only gives an
      // immediate feedback.
      if (this.onModeChange) this.onModeChange();
      else this.onChange();
    }

    // Recursively hides or shows the descendants according to the mode of
    // their parents.
    propagate(card) {
      card.children.forEach((child) => {
        const producing = child.parents.some((p) => p.active && p.mode === MODE_PRODUCE);
        const was = child.active;
        child.active = producing;
        this.paintCard(child);
        if (producing !== was) this.propagate(child);
      });
    }

    refreshTotals() {
      this.columns.forEach((col) => {
        let buy = 0; let sell = 0; let runs = 0;
        col.cards.forEach((card) => {
          if (!card.active) return;
          const p = this.payload.prices[card.item.type_id] || {};
          if (card.mode === MODE_BUY) {
            buy += (p.buy || 0) * card.item.qty_total;
            sell += (p.sell || 0) * card.item.qty_total;
          } else {
            runs += card.item.job_cost || 0;
          }
        });
        Object.assign(col, { buy, sell, runs });
        col.totals.querySelector('[data-t="runs"]').textContent = formatMillions(runs);
        const tip = [col.el.querySelector('.indus-column-title').textContent];
        if (!col.isProducts) {
          col.totals.querySelector('[data-t="sell"]').textContent = formatMillions(sell);
          const market = marketName(this.payload);
          tip.push(t('purchases_sell', { isk: formatIsk(sell), market }), t('purchases_buy', { isk: formatIsk(buy), market }));
        }
        tip.push(t('run_cost', { isk: formatIsk(runs) }));
        col.totals.title = tip.join('\n');
      });
    }

    // Color of the links feeding a card: reaction when the item comes from
    // one, the tool color otherwise.
    colorFor(parent) {
      return parent.item.produced_by_reaction ? LINK_COLOR_REACTION : this.linkColor;
    }

    drawLinks() {
      if (!this.svg || !this.columns.length) return;
      // Collapse the reused layer first: its previous size counts in the
      // container's scroll size and would keep a smaller tree as large.
      this.svg.setAttribute('width', 0);
      this.svg.setAttribute('height', 0);
      const box = this.container.getBoundingClientRect();
      this.svg.setAttribute('width', this.container.scrollWidth);
      this.svg.setAttribute('height', this.container.scrollHeight);
      let paths = '';
      this.columns.forEach((col) => col.cards.forEach((child) => {
        if (!child.active) return;
        child.parents.forEach((parent) => {
          if (!parent.active) return;
          const c = child.el.getBoundingClientRect();
          const p = parent.el.getBoundingClientRect();
          if (!c.width || !p.width) return;
          const x1 = c.left - box.left;
          const y1 = c.top + c.height / 2 - box.top;
          const x2 = p.right - box.left;
          const y2 = p.top + p.height / 2 - box.top;
          paths += '<line x1="' + x1 + '" y1="' + y1 + '" x2="' + x2 + '" y2="' + y2 +
            '" stroke="' + this.colorFor(parent) + '" stroke-width="1.8" data-p="' + parent.key + '" data-c="' + child.key + '"/>';
        });
      }));
      this.svg.innerHTML = paths;
      if (this.hovered) this.highlight(this.hovered);
    }

    unpin() {
      this.pinned = null;
      this.highlight(null);
    }

    // Highlights a card, all its ancestors (the items it is used to
    // produce) and all its descendants (what it takes to produce it), and
    // the links between them; the rest of the tree fades out. null: back to
    // the normal display.
    highlight(card) {
      this.hovered = card && card.active ? card : null;
      // A pinned card that disappears (branch switched to buy) releases the tree.
      if (this.pinned && (!this.hovered || this.hovered.key !== this.pinned)) this.pinned = null;
      const related = new Set();
      if (this.hovered) {
        const walk = (c, next) => {
          if (related.has(c) && c !== this.hovered) return;
          related.add(c);
          next(c).forEach((n) => { if (n.active) walk(n, next); });
        };
        walk(this.hovered, (c) => c.parents);
        walk(this.hovered, (c) => c.children);
      }

      const on = related.size > 0;
      this.container.classList.toggle('indus-hovering', on);
      this.columns.forEach((col) => col.cards.forEach((c) => {
        c.el.classList.toggle('related', related.has(c));
        c.el.classList.toggle('hovered', c === this.hovered);
        c.el.classList.toggle('pinned', c === this.hovered && this.pinned === c.key);
      }));
      if (!this.svg) return;
      const keys = new Set(Array.from(related, (c) => c.key));
      this.svg.classList.toggle('indus-hovering', on);
      this.svg.querySelectorAll('line').forEach((line) => {
        line.classList.toggle('related', on && keys.has(line.dataset.p) && keys.has(line.dataset.c));
      });
    }

    openMenu(card, x, y) {
      this.closeMenu();
      const menu = document.createElement('div');
      menu.className = 'dropdown-menu show indus-menu';
      const add = (label, checked, handler) => {
        const a = document.createElement('a');
        a.className = 'dropdown-item' + (checked ? ' active' : '');
        a.href = '#';
        a.textContent = (checked ? '✓ ' : '') + label;
        a.addEventListener('click', (e) => { e.preventDefault(); this.closeMenu(); handler(); });
        menu.appendChild(a);
      };
      add(t('menu_buy', { letter: t('mode_buy_short') }), card.mode === MODE_BUY, () => this.setMode(card, MODE_BUY));
      add(t('menu_produce', { letter: t('mode_produce_short') }), card.mode === MODE_PRODUCE, () => this.setMode(card, MODE_PRODUCE));

      const structures = this.payload.structures[card.item.type_id];
      if (structures && structures.available.length) {
        menu.insertAdjacentHTML('beforeend', '<div class="dropdown-divider"></div><h6 class="dropdown-header">' + escapeHtml(t('menu_structure')) + '</h6>');
        structures.available.forEach((s) => {
          add(s.name, String(s.id) === String(structures.selected), () => {
            if (String(s.id) !== String(structures.selected)) this.onStructure(card.item.type_id, s.id);
          });
        });
      }
      menu.style.position = 'fixed';
      menu.style.left = x + 'px';
      menu.style.top = y + 'px';
      menu.addEventListener('click', (e) => e.stopPropagation());
      document.body.appendChild(menu);
      this.menu = menu;
    }

    closeMenu() {
      if (this.menu) { this.menu.remove(); this.menu = null; }
    }

    // Visible nodes with their mode and rank (column).
    planEntries() {
      const entries = [];
      this.columns.forEach((col, rank) => col.cards.forEach((card) => {
        if (card.active) entries.push({ item: card.item, rank, mode: card.mode });
      }));
      return entries;
    }

    // Value of the overproduction of the active cards.
    surplus() {
      let buy = 0; let sell = 0;
      this.columns.forEach((col) => col.cards.forEach((card) => {
        if (!card.active || !(card.item.surplus > 0)) return;
        const p = this.payload.prices[card.item.type_id] || {};
        buy += (p.buy || 0) * card.item.surplus;
        sell += (p.sell || 0) * card.item.surplus;
      }));
      return { buy, sell };
    }
  }

  // ===================================================================
  // Summary
  // ===================================================================

  function setText(id, text, title) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    if (title !== undefined) el.title = title;
  }

  const SUMMARY_TILES = ['tile-cost', 'tile-value', 'tile-margin', 'tile-missing'];

  // Hover bubbles of the summary tiles, filled by updateSummary().
  function bindSummaryTips() {
    SUMMARY_TILES.forEach((id) => {
      const tile = document.getElementById(id);
      if (!tile || tile._tipBound) return;
      tile._tipBound = true;
      tile.addEventListener('mouseenter', (e) => { if (tile._tipHtml) showBubble(tile._tipHtml, e); });
      tile.addEventListener('mousemove', (e) => moveBubble(e));
      tile.addEventListener('mouseleave', hideBubble);
    });
  }

  const tileTitle = (id) => document.getElementById(id).querySelector('.indus-tile-label').textContent.trim();

  function clearSummary() {
    ['sum-total', 'sum-purchases', 'sum-runs', 'sum-value-sell', 'sum-value-buy', 'sum-margin-sell',
      'sum-profit-sell', 'sum-margin-buy', 'sum-profit-buy', 'sum-missing', 'sum-surplus-sell'].forEach((id) => setText(id, DASH, ''));
    setText('sum-missing-note', t('stock_deducted'));
    SUMMARY_TILES.forEach((id) => { document.getElementById(id)._tipHtml = null; });
    ['margin-sell', 'margin-buy'].forEach((id) => document.getElementById(id).classList.remove('pos', 'neg'));
    document.getElementById('sum-bar-purchases').style.width = '0';
    document.getElementById('sum-bar-jobs').style.width = '0';
  }

  // `deduct`: state of the "Deduct stock" option of the plan, which also
  // drives the "Left to buy" tile.
  function updateSummary(tree, planData, deduct = true) {
    const payload = tree.payload;
    if (!payload) { clearSummary(); return; }
    const result = payload.result;
    const market = marketName(payload);
    bindSummaryTips();
    document.querySelectorAll('.js-market').forEach((el) => { el.textContent = market; });

    let totalRuns = tree.columns.reduce((s, c) => s + c.runs, 0);
    if (totalRuns === 0 && result.total_job_cost > 0) totalRuns = result.total_job_cost;
    const totalPurchases = tree.columns.reduce((s, c) => s + c.sell, 0);
    const totalCost = totalPurchases + totalRuns;

    // Total cost: purchases (Jita sell) + jobs, with their respective share.
    setText('sum-total', formatShort(totalCost));
    setText('sum-purchases', formatShort(totalPurchases));
    setText('sum-runs', formatShort(totalRuns));
    document.getElementById('sum-bar-purchases').style.width = (totalCost ? totalPurchases / totalCost * 100 : 0) + '%';
    document.getElementById('sum-bar-jobs').style.width = (totalCost ? totalRuns / totalCost * 100 : 0) + '%';
    let jobDetail = '';
    if (result.job_cost > 0) jobDetail += tipRow(t('sum_job_sci'), tipIsk(result.job_cost));
    if (result.scc_cost > 0) jobDetail += tipRow(t('sum_job_scc'), tipIsk(result.scc_cost));
    if (result.facility_cost > 0) jobDetail += tipRow(t('sum_job_facility'), tipIsk(result.facility_cost));
    document.getElementById('tile-cost')._tipHtml = tipHead(tileTitle('tile-cost'), tipIsk(totalCost)) +
      tipSection(null,
        tipRow(t('sum_purchases', { market }), tipIsk(totalPurchases), { dot: 'purchases' }) +
        tipRow(t('sum_run_cost'), totalRuns > 0 ? tipIsk(totalRuns) : (payload.has_structures ? t('adjusted_unavailable') : t('no_structure_selected')), { dot: 'jobs' })) +
      (jobDetail ? tipSection(t('sum_job_detail'), jobDetail) : '');

    // Produced value.
    const p = payload.prices[result.output_type_id] || {};
    const outBuy = (p.buy || 0) * result.output_quantity;
    const outSell = (p.sell || 0) * result.output_quantity;
    setText('sum-value-sell', formatShort(outSell));
    setText('sum-value-buy', formatShort(outBuy));
    document.getElementById('tile-value')._tipHtml =
      tipHead(tileTitle('tile-value'), formatNumber(result.output_quantity) + ' × ' + result.output_name) +
      tipSection(null, tipRow(t('sum_price_sell', { market }), tipIsk(outSell)) + tipRow(t('sum_price_buy', { market }), tipIsk(outBuy)));

    // Margin: (value - total cost) / total cost, at the Jita sell price on
    // the left and at the Jita buy price on the right; each half takes the
    // color of its sign.
    const marginTile = document.getElementById('tile-margin');
    [['sell', outSell], ['buy', outBuy]].forEach(([side, value]) => {
      const half = document.getElementById('margin-' + side);
      half.classList.remove('pos', 'neg');
      if (totalCost > 0 && value > 0) {
        const profit = value - totalCost;
        setText('sum-margin-' + side, pct(profit / totalCost * 100));
        setText('sum-profit-' + side, (profit >= 0 ? '+' : '') + formatShort(profit));
        half.classList.add(profit >= 0 ? 'pos' : 'neg');
      } else {
        setText('sum-margin-' + side, DASH);
        setText('sum-profit-' + side, DASH);
      }
    });
    const profitRow = (label, value) => tipRow(label, value > 0 && totalCost > 0 ? tipIsk(value - totalCost) : DASH,
      { sign: value > 0 && totalCost > 0 ? (value >= totalCost ? 'pos' : 'neg') : '' });
    marginTile._tipHtml = tipHead(tileTitle('tile-margin')) +
      tipSection(null, profitRow(t('sum_profit_sell', { market }), outSell) + profitRow(t('sum_profit_buy', { market }), outBuy)) +
      tipSection(null, tipNote(t('sum_margin_formula'), true));

    // Left to buy once the stock is deducted, and value of the overproduction.
    const surplus = tree.surplus();
    setText('sum-surplus-sell', formatShort(surplus.sell));
    const surplusSection = tipSection(t('sum_surplus'),
      tipRow(t('sum_price_sell', { market }), tipIsk(surplus.sell)) + tipRow(t('sum_price_buy', { market }), tipIsk(surplus.buy)));
    let missingHtml = tipHead(tileTitle('tile-missing')) + surplusSection;
    if (planData && planData.stock_known) {
      const missing = planData.purchases.reduce((s, l) => s + l.unit_price * (deduct ? l.to_buy : l.quantity), 0);
      setText('sum-missing', formatShort(missing));
      setText('sum-missing-note', t(deduct ? 'stock_deducted' : 'stock_not_deducted'));
      missingHtml = tipHead(tileTitle('tile-missing'), tipIsk(missing)) +
        tipSection(null, tipRow(t(deduct ? 'sum_left_deducted' : 'sum_left_not_deducted'), tipIsk(missing))) + surplusSection;
    } else if (planData) {
      setText('sum-missing', formatShort(totalPurchases));
      setText('sum-missing-note', t('no_asset_source'));
      missingHtml = tipHead(tileTitle('tile-missing'), tipIsk(totalPurchases)) +
        tipSection(null, tipNote(t('sum_no_asset_source'))) + surplusSection;
    }
    document.getElementById('tile-missing')._tipHtml = missingHtml;
  }

  // ===================================================================
  // Production plan
  // ===================================================================

  // Column widths shared by every section of a view: the tables of each
  // rank (or family) line up under each other. The item takes the rest.
  const JOB_COLS = '<colgroup><col style="width:34px"><col><col style="width:74px"><col style="width:84px">' +
    '<col style="width:84px"><col style="width:66px"><col style="width:66px"><col style="width:58px">' +
    '<col style="width:70px"><col style="width:120px"><col style="width:96px"></colgroup>';
  const PURCHASE_COLS = '<colgroup><col><col style="width:78px"><col style="width:74px"><col style="width:78px">' +
    '<col style="width:70px"><col style="width:72px"><col style="width:74px"></colgroup>';

  // Amount in millions of ISK for the plan tables (the exact amount is in
  // the cell tooltip).
  function millionsCell(value) {
    return value ? formatNumber(value / 1e6, 2) : DASH;
  }

  // Short blueprint state for the plan column.
  function blueprintShort(bp) {
    if (bp.state === 'missing') return t('missing');
    const src = bp.text.match(/ME(\d+)\/TE(\d+)/);
    return (bp.state === 'bpo' ? 'BPO ' : 'BPC ') + (src ? src[1] + '/' + src[2] : '');
  }

  function activityCell(activity) {
    const reaction = activity === 'reaction';
    return '<td style="color:' + (reaction ? LINK_COLOR_REACTION : LINK_COLOR_MANUFACTURING) + '">' +
      escapeHtml(reaction ? t('activity_reaction') : t('activity_manufacturing')) + '</td>';
  }

  function rankTitle(rank) {
    return rank === 0 ? t('rank_final') : t('column_rank', { rank });
  }

  class PlanView {
    constructor(tool, jobsEl, purchasesEl, tabTitleEl) {
      this.tool = tool;
      this.jobsEl = jobsEl;
      this.purchasesEl = purchasesEl;
      this.tabTitleEl = tabTitleEl;
      this.collapsed = {};
      this.compact = true;
      this.deduct = true;
      this.root = null;
      this.data = null;
      this.onDeductChange = null;
    }

    storageKey() {
      return 'indus-planner:done:' + this.tool + ':' + this.root;
    }

    done() {
      try { return new Set(JSON.parse(localStorage.getItem(this.storageKey()) || '[]')); } catch (e) { return new Set(); }
    }

    saveDone(set) {
      try { localStorage.setItem(this.storageKey(), JSON.stringify(Array.from(set))); } catch (e) { /* storage unavailable */ }
    }

    clear() {
      this.data = null;
      this.jobsEl.innerHTML = '';
      this.purchasesEl.innerHTML = '';
      this.tabTitleEl.textContent = t('plan_tab');
    }

    render(data, root) {
      this.data = data;
      this.root = root;
      this.renderJobs();
      this.renderPurchases();
      const jobs = data.jobs.length;
      const buys = data.purchases.length;
      this.tabTitleEl.textContent = t('plan_tab') + (jobs || buys ? ' (' + t('plan_tab_counts', { jobs, purchases: buys }) + ')' : '');
    }

    header(title, tooltip, extra) {
      return '<div class="indus-plan-head"><span class="indus-plan-title" title="' + escapeHtml(tooltip || '') + '">' + escapeHtml(title) + '</span>' +
        (extra || '') +
        '<label class="mb-0 ml-2 small"><input type="checkbox" class="js-compact"' + (this.compact ? ' checked' : '') + '> ' + escapeHtml(t('compact_tables')) + '</label></div>';
    }

    section(key, title, meta, tableHtml, color, actionHtml) {
      const collapsed = !!this.collapsed[key];
      return '<div class="indus-section' + (collapsed ? ' collapsed' : '') + '" data-key="' + escapeHtml(key) + '">' +
        '<div class="indus-section-head" style="background:' + color + '">' +
          '<span class="indus-section-title">' + (collapsed ? '▸ ' : '▾ ') + escapeHtml(title) + '</span>' +
          meta.map((m) => '<span class="indus-section-meta">' + escapeHtml(m) + '</span>').join('') +
          '<span class="flex-grow-1"></span>' + (actionHtml || '') +
        '</div>' + tableHtml + '</div>';
    }

    bindCommon(root) {
      root.querySelectorAll('.indus-section-head').forEach((head) => {
        head.addEventListener('click', (e) => {
          if (e.target.closest('button')) return;
          const section = head.parentElement;
          const key = section.dataset.key;
          this.collapsed[key] = !this.collapsed[key];
          section.classList.toggle('collapsed', this.collapsed[key]);
          head.querySelector('.indus-section-title').textContent =
            (this.collapsed[key] ? '▸ ' : '▾ ') + head.querySelector('.indus-section-title').textContent.slice(2);
        });
      });
      root.querySelectorAll('.js-compact').forEach((chk) => chk.addEventListener('change', () => {
        this.compact = chk.checked;
        this.renderJobs();
        this.renderPurchases();
      }));
      root.querySelectorAll('table.indus-plan-table').forEach(makeSortable);
    }

    renderJobs() {
      const lines = this.data.jobs;
      const done = this.done();
      const byRank = new Map();
      lines.forEach((l) => { if (!byRank.has(l.rank)) byRank.set(l.rank, []); byRank.get(l.rank).push(l); });

      const exportButton = this.exporter
        ? '<button type="button" class="btn btn-xs btn-success ml-auto js-export" title="' + escapeHtml(t('export_excel_help')) + '"' +
          (lines.length || this.data.purchases.length ? '' : ' disabled') + '><i class="fas fa-file-excel"></i> ' + escapeHtml(t('export_excel')) + '</button>'
        : '';
      let html = this.header(t('jobs_title'), t('jobs_title_help'), exportButton);
      byRank.forEach((group, rank) => {
        const longest = Math.max.apply(null, group.map((l) => l.seconds));
        const rows = group.map((l) => {
          const isDone = done.has(l.type_id);
          const bp = blueprintLabel(l.blueprint);
          return '<tr class="' + (isDone ? 'done' : '') + '">' +
            '<td data-sort="' + (isDone ? 1 : 0) + '"><input type="checkbox" class="js-done" data-type="' + l.type_id + '"' + (isDone ? ' checked' : '') + '></td>' +
            '<td class="name" title="' + escapeHtml(l.name) + '"><img src="' + icon(l.type_id, 32) + '" alt=""> ' + escapeHtml(l.name) + '</td>' +
            activityCell(l.activity) +
            '<td class="text-right" data-sort="' + l.runs + '" title="' + escapeHtml(jobsText(l.runs, l.runs_per_job, true)) + '">' + escapeHtml(jobsText(l.runs, l.runs_per_job)) + '</td>' +
            '<td class="text-right" data-sort="' + l.seconds + '">' + (l.seconds ? formatDuration(l.seconds) : DASH) + '</td>' +
            '<td class="text-right" data-sort="' + l.qty_produced + '">' + formatNumber(l.qty_produced) + '</td>' +
            '<td class="text-right" data-sort="' + l.qty_needed + '">' + formatNumber(l.qty_needed) + '</td>' +
            '<td class="text-right" data-sort="' + l.surplus + '">' + (l.surplus ? formatNumber(l.surplus) : DASH) + '</td>' +
            '<td class="text-right" data-sort="' + l.job_cost + '" title="' + escapeHtml(formatIsk(l.job_cost)) + '">' + millionsCell(l.job_cost) + '</td>' +
            '<td title="' + escapeHtml(l.structure_name || '') + '">' + escapeHtml(l.structure_name || DASH) + '</td>' +
            '<td title="' + escapeHtml(bp ? bp.text : '') + '">' + (bp ? blueprintIcon(bp) + ' ' + escapeHtml(blueprintShort(bp)) : DASH) + '</td>' +
            '</tr>';
        }).join('');
        const table = '<table class="table table-sm table-striped mb-0 indus-plan-table' + (this.compact ? ' compact' : '') + '">' + JOB_COLS + '<thead><tr>' +
          '<th>' + escapeHtml(t('col_done')) + '</th><th>' + escapeHtml(t('col_item')) + '</th><th>' + escapeHtml(t('col_activity')) + '</th>' +
          '<th class="text-right">' + escapeHtml(t('card_runs')) + '</th><th class="text-right">' + escapeHtml(t('col_duration')) + '</th>' +
          '<th class="text-right">' + escapeHtml(t('col_produced')) + '</th><th class="text-right">' + escapeHtml(t('col_needed')) + '</th>' +
          '<th class="text-right">' + escapeHtml(t('card_surplus')) + '</th>' +
          '<th class="text-right" title="' + escapeHtml(t('col_cost_help')) + '">' + escapeHtml(t('col_cost')) + '</th>' +
          '<th>' + escapeHtml(t('col_structure')) + '</th><th>' + escapeHtml(t('col_blueprint')) + '</th></tr></thead><tbody>' + rows + '</tbody></table>';
        html += this.section('rank-' + rank, rankTitle(rank),
          [t('jobs_count', { count: group.length }), t('longest', { duration: longest ? formatDuration(longest) : DASH })],
          table, rank === 0 ? '#6a5acd' : '#3b4a6b');
      });

      if (!lines.length) {
        html += '<p class="text-muted">' + escapeHtml(t('no_jobs')) + '</p>';
      } else {
        const finished = lines.filter((l) => done.has(l.type_id)).length;
        const seconds = lines.reduce((s, l) => s + l.seconds, 0);
        const cost = lines.reduce((s, l) => s + l.job_cost, 0);
        html += '<p class="indus-plan-summary" title="' + escapeHtml(t('jobs_summary_help')) + '">' +
          escapeHtml(t('jobs_summary', { count: lines.length, done: finished, duration: formatDuration(seconds), cost: formatIsk(cost) })) + '</p>';
      }
      this.jobsEl.innerHTML = html;
      this.bindCommon(this.jobsEl);
      const exportBtn = this.jobsEl.querySelector('.js-export');
      if (exportBtn) exportBtn.addEventListener('click', () => this.exportExcel(exportBtn));
      this.jobsEl.querySelectorAll('.js-done').forEach((chk) => chk.addEventListener('change', () => {
        const set = this.done();
        const typeId = Number(chk.dataset.type);
        if (chk.checked) set.add(typeId); else set.delete(typeId);
        this.saveDone(set);
        this.renderJobs();
      }));
    }

    toBuy(line) {
      return this.deducting() ? line.to_buy : line.quantity;
    }

    deducting() {
      return this.data.stock_known && this.deduct;
    }

    multibuy(lines) {
      return lines.map((l) => [l.name, this.toBuy(l)]).filter(([, q]) => q > 0).map(([n, q]) => n + '\t' + q).join('\n');
    }

    renderPurchases() {
      const lines = this.data.purchases;
      const known = this.data.stock_known;
      const extra =
        '<button type="button" class="btn btn-xs btn-primary ml-auto js-copy-all" title="' + escapeHtml(t('copy_all_help')) + '"' + (lines.length ? '' : ' disabled') + '>' + escapeHtml(t('copy_multibuy')) + '</button>' +
        '<label class="mb-0 ml-2 small" title="' + escapeHtml(t('deduct_stock_help')) + '"><input type="checkbox" class="js-deduct"' +
        (this.deduct ? ' checked' : '') + (known ? '' : ' disabled') + '> ' + escapeHtml(t('deduct_stock')) + '</label>';
      let html = this.header(t('purchases_title'), '', extra);

      const byGroup = new Map();
      lines.forEach((l) => { if (!byGroup.has(l.group_name)) byGroup.set(l.group_name, []); byGroup.get(l.group_name).push(l); });
      byGroup.forEach((members, group) => {
        const rows = members.map((l) => {
          const buy = this.toBuy(l);
          return '<tr>' +
            '<td class="name" title="' + escapeHtml(l.name) + '"><img src="' + icon(l.type_id, 32) + '" alt=""> ' + escapeHtml(l.name) + '</td>' +
            '<td class="text-right" data-sort="' + l.quantity + '">' + formatNumber(l.quantity) + '</td>' +
            '<td class="text-right" data-sort="' + l.total_volume + '">' + (l.total_volume ? formatNumber(l.total_volume, 1) : DASH) + '</td>' +
            '<td class="text-right' + (l.price_fallback ? ' indus-price-fallback' : '') + '" data-sort="' + l.unit_price + '"' +
              (l.price_fallback ? ' title="' + escapeHtml(t('price_fallback', { market: marketName(this.data) })) + '"' : '') + '>' +
              (l.unit_price ? formatNumber(l.unit_price, 2) : DASH) + (l.price_fallback ? ' *' : '') + '</td>' +
            '<td class="text-right" data-sort="' + l.total_price + '" title="' + escapeHtml(formatIsk(l.total_price)) + '">' + millionsCell(l.total_price) + '</td>' +
            '<td class="text-right" data-sort="' + (known ? l.in_stock : -1) + '" title="' + escapeHtml(l.stock_tooltip) + '">' + (known ? formatNumber(l.in_stock) : DASH) + '</td>' +
            '<td class="text-right font-weight-bold" data-sort="' + buy + '">' + formatNumber(buy) + '</td>' +
            '</tr>';
        }).join('');
        const table = '<table class="table table-sm table-striped mb-0 indus-plan-table' + (this.compact ? ' compact' : '') + '">' + PURCHASE_COLS + '<thead><tr>' +
          '<th>' + escapeHtml(t('col_material')) + '</th><th class="text-right">' + escapeHtml(t('col_needed')) + '</th>' +
          '<th class="text-right">' + escapeHtml(t('col_volume')) + '</th>' +
          '<th class="text-right" title="' + escapeHtml(t('col_unit_price_help', { market: marketName(this.data) })) + '">' + escapeHtml(t('col_unit_price')) + '</th>' +
          '<th class="text-right" title="' + escapeHtml(t('col_total_help')) + '">' + escapeHtml(t('col_total')) + '</th>' +
          '<th class="text-right">' + escapeHtml(t('col_in_stock')) + '</th><th class="text-right">' + escapeHtml(t('col_to_buy')) + '</th></tr></thead><tbody>' + rows + '</tbody></table>';
        const total = members.reduce((s, m) => s + m.total_price, 0);
        html += this.section('group-' + group, group, [t('group_total', { isk: formatIskShort(total) })], table, '#3b4a6b',
          '<button type="button" class="btn btn-xs btn-light js-copy-group" data-group="' + escapeHtml(group) + '">' + escapeHtml(t('copy_multibuy')) + '</button>');
      });

      if (!lines.length) {
        html += '<p class="text-muted">' + escapeHtml(t('no_purchases')) + '</p>';
      } else {
        const total = lines.reduce((s, l) => s + l.total_price, 0);
        const missing = lines.reduce((s, l) => s + l.unit_price * this.toBuy(l), 0);
        const volume = lines.reduce((s, l) => s + l.unit_volume * this.toBuy(l), 0);
        html += '<p class="indus-plan-summary">' +
          escapeHtml(t('purchases_summary', { count: lines.length, need: formatIsk(total), buy: formatIsk(missing), volume: formatNumber(volume, 1) })) +
          (known ? '' : ' · <span class="text-warning">' + escapeHtml(t('stock_unknown_warning')) + '</span>') + '</p>';
      }
      this.purchasesEl.innerHTML = html;
      this.bindCommon(this.purchasesEl);

      const deduct = this.purchasesEl.querySelector('.js-deduct');
      if (deduct) deduct.addEventListener('change', () => {
        this.deduct = deduct.checked;
        this.renderPurchases();
        if (this.onDeductChange) this.onDeductChange();
      });
      const copyAll = this.purchasesEl.querySelector('.js-copy-all');
      if (copyAll) copyAll.addEventListener('click', () => this.copy(lines, copyAll, t('copy_multibuy')));
      this.purchasesEl.querySelectorAll('.js-copy-group').forEach((btn) => btn.addEventListener('click', () => {
        this.copy(lines.filter((l) => l.group_name === btn.dataset.group), btn, t('copy_multibuy'));
      }));
    }

    // Both tables as displayed (done jobs, stock deducted or not), laid out
    // by the server: one sheet per table, a band per rank or family.
    exportPayload() {
      const done = this.done();
      const known = this.data.stock_known;
      const jobs = this.data.jobs;
      const byRank = new Map();
      jobs.forEach((l) => { if (!byRank.has(l.rank)) byRank.set(l.rank, []); byRank.get(l.rank).push(l); });
      const jobSections = Array.from(byRank, ([rank, group]) => {
        const longest = Math.max.apply(null, group.map((l) => l.seconds));
        return {
          title: [rankTitle(rank), t('jobs_count', { count: group.length }), t('longest', { duration: longest ? formatDuration(longest) : DASH })].join('  ·  '),
          color: rank === 0 ? '#6a5acd' : '#3b4a6b',
          rows: group.map((l) => {
            const bp = blueprintLabel(l.blueprint);
            const reaction = l.activity === 'reaction';
            return [done.has(l.type_id), l.name,
              { v: reaction ? t('activity_reaction') : t('activity_manufacturing'), c: reaction ? LINK_COLOR_REACTION : LINK_COLOR_MANUFACTURING },
              l.runs, jobsText(l.runs, l.runs_per_job, true), l.seconds || null, l.qty_produced, l.qty_needed, l.surplus || null, l.job_cost || null,
              l.structure_name || null, bp ? blueprintShort(bp) : null];
          }),
        };
      });

      const purchases = this.data.purchases;
      const byGroup = new Map();
      purchases.forEach((l) => { if (!byGroup.has(l.group_name)) byGroup.set(l.group_name, []); byGroup.get(l.group_name).push(l); });
      const purchaseSections = Array.from(byGroup, ([group, members]) => ({
        title: group + '  ·  ' + t('group_total', { isk: formatIskShort(members.reduce((s, m) => s + m.total_price, 0)) }),
        color: '#3b4a6b',
        rows: members.map((l) => [l.name, l.quantity, l.total_volume || null,
          l.price_fallback ? { v: l.unit_price || null, c: '#D39E00' } : (l.unit_price || null), l.total_price || null,
          known ? l.in_stock : null, this.toBuy(l)]),
      }));

      const jobsFooter = jobs.length ? t('jobs_summary', {
        count: jobs.length,
        done: jobs.filter((l) => done.has(l.type_id)).length,
        duration: formatDuration(jobs.reduce((s, l) => s + l.seconds, 0)),
        cost: formatIsk(jobs.reduce((s, l) => s + l.job_cost, 0)),
      }) : t('no_jobs');
      const purchasesFooter = purchases.length ? t('purchases_summary', {
        count: purchases.length,
        need: formatIsk(purchases.reduce((s, l) => s + l.total_price, 0)),
        buy: formatIsk(purchases.reduce((s, l) => s + l.unit_price * this.toBuy(l), 0)),
        volume: formatNumber(purchases.reduce((s, l) => s + l.unit_volume * this.toBuy(l), 0), 1),
      }) + (known ? '' : ' · ' + t('stock_unknown_warning'))
        + (purchases.some((l) => l.price_fallback) ? ' · ' + t('price_fallback_note', { market: marketName(this.data) }) : '') : t('no_purchases');

      return {
        title: this.exporter.title(),
        sheets: [
          {
            name: t('sheet_jobs'),
            columns: [
              { label: t('col_done'), type: 'check', width: 7 },
              { label: t('col_item'), type: 'text', width: 40 },
              { label: t('col_activity'), type: 'text', width: 15 },
              { label: t('card_runs'), type: 'int', width: 9 },
              { label: t('col_jobs'), type: 'text', width: 24 },
              { label: t('col_duration'), type: 'duration', width: 12 },
              { label: t('col_produced'), type: 'int', width: 12 },
              { label: t('col_needed'), type: 'int', width: 12 },
              { label: t('card_surplus'), type: 'int', width: 10 },
              { label: t('col_cost_isk'), type: 'isk', width: 16 },
              { label: t('col_structure'), type: 'text', width: 34 },
              { label: t('col_blueprint'), type: 'text', width: 16 },
            ],
            sections: jobSections,
            footer: jobsFooter,
          },
          {
            name: t('sheet_purchases'),
            columns: [
              { label: t('col_material'), type: 'text', width: 40 },
              { label: t('col_needed'), type: 'int', width: 13 },
              { label: t('col_volume'), type: 'dec1', width: 13 },
              { label: t('col_unit_price'), type: 'dec2', width: 15 },
              { label: t('col_total_isk'), type: 'isk', width: 17 },
              { label: t('col_in_stock'), type: 'int', width: 11 },
              { label: t('col_to_buy'), type: 'int', width: 11, bold: true },
            ],
            sections: purchaseSections,
            footer: purchasesFooter,
          },
        ],
      };
    }

    exportExcel(button) {
      if (!this.data || !this.exporter) return;
      const label = button.innerHTML;
      button.disabled = true;
      button.textContent = t('export_running');
      fetch(this.exporter.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/json', 'X-CSRF-TOKEN': this.exporter.csrf },
        body: JSON.stringify(this.exportPayload()),
      }).then((response) => {
        if (!response.ok) throw new Error(String(response.status));
        const match = (response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/);
        return response.blob().then((blob) => [blob, match ? match[1] : 'indus-planner.xlsx']);
      }).then(([blob, filename]) => {
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        button.innerHTML = label;
      }).catch(() => {
        button.textContent = t('export_failed');
        setTimeout(() => { button.innerHTML = label; }, 3000);
      }).finally(() => { button.disabled = false; });
    }

    copy(lines, button, label) {
      const text = this.multibuy(lines);
      const rows = text ? text.split('\n').length : 0;
      const covered = lines.length - rows;
      const restore = () => setTimeout(() => { button.textContent = label; }, 2000);
      if (!rows) {
        button.textContent = t('nothing_to_copy');
        if (covered) button.title = t('all_covered');
        restore();
        return;
      }
      const done = () => {
        button.textContent = t('copied', { count: rows });
        if (covered) button.title = t('covered_not_copied', { count: covered });
        restore();
      };
      const fallback = () => {
        const area = document.createElement('textarea');
        area.value = text;
        document.body.appendChild(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        if (ok) done(); else { button.textContent = t('copy_refused'); restore(); }
      };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(done, fallback);
      else fallback();
    }
  }

  // Sorts a table when a header is clicked (numeric data-sort value when set).
  function makeSortable(table) {
    table.querySelectorAll('thead th').forEach((th, index) => {
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => {
        const asc = th.dataset.dir !== 'asc';
        table.querySelectorAll('thead th').forEach((h) => { delete h.dataset.dir; h.classList.remove('sorted-asc', 'sorted-desc'); });
        th.dataset.dir = asc ? 'asc' : 'desc';
        th.classList.add(asc ? 'sorted-asc' : 'sorted-desc');
        const body = table.tBodies[0];
        const rows = Array.from(body.rows);
        rows.sort((a, b) => {
          const ca = a.cells[index]; const cb = b.cells[index];
          const va = ca.dataset.sort !== undefined ? Number(ca.dataset.sort) : ca.textContent.trim().toLowerCase();
          const vb = cb.dataset.sort !== undefined ? Number(cb.dataset.sort) : cb.textContent.trim().toLowerCase();
          return (va < vb ? -1 : va > vb ? 1 : 0) * (asc ? 1 : -1);
        });
        rows.forEach((r) => body.appendChild(r));
      });
    });
  }

  // ===================================================================
  // Tool: parameters <-> server <-> views
  // ===================================================================

  class Tool {
    constructor(name, cfg, linkColor) {
      this.name = name;
      this.cfg = cfg;
      this.overrides = {};
      // Margin optimisation: modes before it, restored when unchecked.
      this.optimizeInput = document.getElementById('p-optimize');
      this.optimizeSnapshot = null;
      if (this.optimizeInput) {
        this.optimizeInput.addEventListener('change', () => {
          if (this.optimizeInput.checked) {
            this.optimizeSnapshot = new Map(this.tree.userModes || []);
            this.compute();
          } else {
            this.tree.userModes = new Map(this.optimizeSnapshot || []);
            this.optimizeSnapshot = null;
            this.compute({ modesOnly: true });
          }
        });
      }
      this.seq = 0;
      this.planSeq = 0;
      this.planData = null;
      this.storageKey = cfg.storageKey || ('indus-planner:state:' + name);
      this.alerts = document.getElementById('indus-alerts');
      this.tree = new Tree(document.getElementById('indus-tree'), linkColor, {
        onChange: () => { updateSummary(this.tree, this.planData, this.plan.deduct); this.refreshPlan(); this.saveState(); },
        onStructure: (typeId, structureId) => { this.overrides[typeId] = structureId; this.compute(); },
        // A manual switch keeps the other choices: no new optimisation.
        onModeChange: () => this.compute({ modesOnly: true }),
      });
      this.plan = new PlanView(name, document.getElementById('indus-jobs'), document.getElementById('indus-purchases'),
        document.getElementById('indus-plan-tab-title'));
      this.plan.onDeductChange = () => updateSummary(this.tree, this.planData, this.plan.deduct);
      if (cfg.exportUrl) {
        this.plan.exporter = {
          url: cfg.exportUrl,
          csrf: cfg.csrf,
          title: () => (this.tree.payload
            ? this.tree.payload.result.output_name + ' × ' + formatNumber(this.tree.payload.tree.output.qty_total) : ''),
        };
      }

      // Puts the tree and the plan aside (Industry > My plans).
      this.saveName = document.getElementById('indus-save-name');
      this.saveBtn = document.getElementById('indus-save-btn');
      if (this.saveBtn) {
        this.saveName.addEventListener('input', () => { this.saveName.dataset.touched = '1'; });
        this.saveBtn.addEventListener('click', () => this.savePlan());
      }
    }

    // Suggested name: item and quantity, as long as the user typed nothing.
    updateSaveBar() {
      if (!this.saveBtn) return;
      const payload = this.tree.payload;
      this.saveBtn.disabled = !payload;
      this.saveName.disabled = !payload;
      if (payload && !this.saveName.dataset.touched) {
        this.saveName.value = payload.result.output_name + ' × ' + formatNumber(payload.tree.output.qty_total);
      }
    }

    savePlan() {
      const payload = this.tree.payload;
      const name = this.saveName.value.trim();
      if (!payload) return;
      if (!name) { this.showAlert(t('plan_name_required')); return; }
      this.saveBtn.disabled = true;
      this.post(this.cfg.saveUrl, {
        name,
        tool: this.name,
        params: this.params(),
        payload,
        entries: this.tree.planEntries(),
        user_modes: Array.from(this.tree.userModes || []),
      }).then((res) => {
        this.alerts.innerHTML = '<div class="alert alert-success py-2">' + escapeHtml(res.message) +
          ' <a href="' + escapeHtml(res.url) + '">' + escapeHtml(t('open_plan')) + '</a> · ' + escapeHtml(t('find_in_plans')) + '</div>';
        delete this.saveName.dataset.touched;
      }).catch((error) => this.showAlert(t('save_failed', { error })))
        .finally(() => { this.saveBtn.disabled = false; });
    }

    // Latest input of the tool (parameters, structure choices and buy /
    // produce), remembered in the browser per SeAT account to find it again
    // when coming back to the page.
    loadState() {
      try { return JSON.parse(localStorage.getItem(this.storageKey) || 'null'); } catch (e) { return null; }
    }

    saveState() {
      const state = Object.assign({}, this.extraState ? this.extraState() : {}, {
        params: this.params(),
        overrides: this.overrides,
        userModes: this.tree.userModes ? Array.from(this.tree.userModes) : [],
        optimize: !!(this.optimizeInput && this.optimizeInput.checked),
        optimizeSnapshot: this.optimizeSnapshot ? Array.from(this.optimizeSnapshot) : null,
      });
      try { localStorage.setItem(this.storageKey, JSON.stringify(state)); } catch (e) { /* storage unavailable */ }
    }

    // New final product: structure and buy/produce choices start over.
    resetChoices() {
      this.overrides = {};
      this.tree.userModes = new Map();
      if (this.optimizeSnapshot) this.optimizeSnapshot = new Map();
    }


    restoreChoices(state) {
      this.overrides = (state && state.overrides) || {};
      this.tree.userModes = new Map((state && state.userModes) || []);
      if (this.optimizeInput) {
        this.optimizeInput.checked = !!(state && state.optimize);
        this.optimizeSnapshot = state && state.optimizeSnapshot ? new Map(state.optimizeSnapshot) : null;
      }
    }

    post(url, body) {
      return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.cfg.csrf },
        body: JSON.stringify(body),
      }).then((r) => r.json().then((data) => (r.ok ? data : Promise.reject(data.error || data.message || ('HTTP ' + r.status)))));
    }

    showAlert(message, level) {
      this.alerts.innerHTML = message ? '<div class="alert alert-' + (level || 'danger') + ' py-2">' + escapeHtml(message) + '</div>' : '';
    }

    schedule() {
      clearTimeout(this.timer);
      this.timer = setTimeout(() => this.compute(), 250);
    }

    compute(options = {}) {
      const params = this.params();
      this.saveState();
      if (!params) {
        this.tree.clear(this.emptyMessage);
        this.updateSaveBar();
        this.plan.clear();
        clearSummary();
        return;
      }
      params.overrides = this.overrides;
      const seq = ++this.seq;
      const buyKeys = () => Array.from(this.tree.userModes || []).filter(([, mode]) => mode === MODE_BUY).map(([key]) => key);
      const optimise = this.optimizeInput && this.optimizeInput.checked && !options.modesOnly;
      let switched = null;

      // Optimisation: evaluated on the full tree, then the tree is computed
      // again with the chosen modes.
      const ready = optimise
        ? this.post(this.cfg.computeUrl, Object.assign({}, params, { buy: [] })).then((full) => {
          if (seq !== this.seq) return Promise.reject(null);
          const modes = optimizeModes(full);
          switched = 0;
          modes.forEach((mode, key) => {
            if (mode === MODE_BUY) switched++;
            this.tree.userModes.set(key, mode);
          });
          this.saveState();
        })
        : Promise.resolve();

      ready.then(() => this.post(this.cfg.computeUrl, Object.assign({}, params, { buy: buyKeys() }))).then((payload) => {
        if (seq !== this.seq) return;
        if (!payload.adjusted_prices_known) this.showAlert(t('adjusted_prices_missing'), 'warning');
        else if (switched !== null) this.showAlert(switched ? t('optimized', { count: switched }) : t('optimized_none'), 'info');
        else this.showAlert('');
        this.tree.render(payload);
        this.updateSaveBar();
        updateSummary(this.tree, this.planData, this.plan.deduct);
        this.refreshPlan();
      }).catch((error) => {
        if (error === null || seq !== this.seq) return;
        this.showAlert(t('compute_failed', { error }));
      });
    }

    refreshPlan() {
      clearTimeout(this.planTimer);
      this.planTimer = setTimeout(() => {
        if (!this.tree.payload) return;
        const seq = ++this.planSeq;
        const root = this.tree.payload.result.output_type_id;
        const params = this.params();
        this.post(this.cfg.planUrl, { entries: this.tree.planEntries(), market: params ? params.market : null }).then((data) => {
          if (seq !== this.planSeq) return;
          this.planData = data;
          this.plan.render(data, root);
          updateSummary(this.tree, data, this.plan.deduct);
        }).catch((error) => this.showAlert(t('plan_failed', { error })));
      }, 150);
    }
  }

  // ------------------------------------------------------------ Reactions

  function reactions(cfg) {
    const tool = new Tool('reactions', cfg, LINK_COLOR_REACTION);
    const $ = (id) => document.getElementById(id);
    tool.emptyMessage = t('pick_reaction');

    tool.params = () => {
      const typeId = Number($('p-reaction').value);
      if (!typeId) return null;
      const mode = document.querySelector('input[name="p-mode"]:checked').value;
      return {
        character_id: Number($('p-character').value) || null,
        structure_id: $('p-structure').value || null,
        type_id: typeId,
        qty_mode: mode,
        qty: Math.max(1, Number($('p-qty').value) || 1),
        runs: Math.max(1, Number($('p-runs').value) || 1),
        market: $('p-market').value,
      };
    };

    const syncMode = () => {
      const mode = document.querySelector('input[name="p-mode"]:checked').value;
      $('p-qty').disabled = mode !== 'qty';
      $('p-runs').disabled = mode !== 'runs';
    };

    const loadReactions = () => fetch(cfg.listUrl + '?category=' + encodeURIComponent($('p-type').value), { headers: { 'Accept': 'application/json' } })
      .then((r) => r.json())
      .then((list) => {
        $('p-reaction').innerHTML = list.map((r) => '<option value="' + r.id + '">' + escapeHtml(r.name) + '</option>').join('');
      });

    $('p-type').addEventListener('change', () => {
      loadReactions().then(() => {
        tool.resetChoices();
        tool.compute();
      });
    });
    $('p-reaction').addEventListener('change', () => { tool.resetChoices(); tool.compute(); });
    ['p-character', 'p-structure', 'p-market'].forEach((id) => $(id).addEventListener('change', () => tool.compute()));
    ['p-qty', 'p-runs'].forEach((id) => $(id).addEventListener('input', () => tool.schedule()));
    document.querySelectorAll('input[name="p-mode"]').forEach((r) => r.addEventListener('change', () => { syncMode(); tool.compute(); }));

    tool.extraState = () => ({ category: $('p-type').value });

    const setSelect = (el, value) => {
      if (value !== null && value !== undefined && Array.from(el.options).some((o) => o.value === String(value))) el.value = String(value);
    };
    const saved = tool.loadState();
    const restore = () => {
      const p = saved.params;
      setSelect($('p-character'), p.character_id);
      setSelect($('p-structure'), p.structure_id);
      setSelect($('p-reaction'), p.type_id);
      document.getElementById(p.qty_mode === 'runs' ? 'p-mode-runs' : 'p-mode-qty').checked = true;
      $('p-qty').value = p.qty || 1;
      $('p-runs').value = p.runs || 1;
      tool.restoreChoices(saved);
      syncMode();
      tool.compute();
    };

    if (saved && saved.params) {
      if (saved.category && saved.category !== $('p-type').value) {
        setSelect($('p-type'), saved.category);
        loadReactions().then(restore);
      } else {
        restore();
      }
    } else {
      syncMode();
      tool.compute();
    }
  }

  // ----------------------------------------------------------- Production

  function production(cfg) {
    const tool = new Tool('production', cfg, LINK_COLOR_MANUFACTURING);
    const $ = (id) => document.getElementById(id);
    const input = $('p-item');
    const suggestions = $('p-item-suggestions');
    let selected = null;
    tool.emptyMessage = t('pick_item');

    tool.params = () => (selected ? {
      character_id: Number($('p-character').value) || null,
      type_id: selected.id,
      qty: Math.max(1, Number($('p-qty').value) || 1),
      me: Math.min(10, Math.max(0, Number($('p-me').value) || 0)),
      te: Math.min(20, Math.max(0, Number($('p-te').value) || 0)),
      include_reactions: $('p-reactions').checked,
      market: $('p-market').value,
    } : null);

    // ME/TE prefilled from the best owned blueprint.
    const prefill = (typeId, applyEfficiency = true) => fetch(cfg.blueprintUrl + '?type_id=' + typeId, { headers: { 'Accept': 'application/json' } })
      .then((r) => r.json())
      .then((bp) => {
        const label = blueprintLabel(bp);
        const best = bp && (bp.bpo || bp.bpc);
        if (applyEfficiency) {
          $('p-me').value = best ? best.me : 0;
          $('p-te').value = best ? best.te : 0;
        }
        $('p-blueprint').innerHTML = label
          ? blueprintIcon(label) + ' <span class="indus-bp ' + label.cls + '">' + escapeHtml(label.text) + '</span>' +
            (best ? ' <span class="text-muted">(' + escapeHtml(t('me_te_applied')) + ')</span>' : '')
          : '';
      })
      .catch(() => { $('p-blueprint').textContent = ''; });

    const choose = (item) => {
      selected = item;
      input.value = item.name;
      suggestions.innerHTML = '';
      tool.resetChoices();
      prefill(item.id).then(() => tool.compute());
    };

    let searchTimer = null;
    input.addEventListener('input', () => {
      clearTimeout(searchTimer);
      const q = input.value.trim();
      if (q === '') { selected = null; suggestions.innerHTML = ''; tool.compute(); return; }
      if (q.length < 3) { suggestions.innerHTML = ''; return; }
      searchTimer = setTimeout(() => {
        fetch(cfg.searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
          .then((r) => r.json())
          .then((items) => {
            suggestions.innerHTML = '';
            items.forEach((item) => {
              const b = document.createElement('button');
              b.type = 'button';
              b.className = 'list-group-item list-group-item-action py-1';
              b.innerHTML = '<img src="' + icon(item.id, 32) + '" width="20" height="20" alt=""> ' + escapeHtml(item.name);
              b.addEventListener('click', () => choose(item));
              suggestions.appendChild(b);
            });
          });
      }, 200);
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('#p-item-suggestions, #p-item')) suggestions.innerHTML = ''; });

    ['p-character', 'p-market'].forEach((id) => $(id).addEventListener('change', () => tool.compute()));
    $('p-reactions').addEventListener('change', () => tool.compute());
    ['p-qty', 'p-me', 'p-te'].forEach((id) => $(id).addEventListener('input', () => tool.schedule()));

    tool.extraState = () => ({ item: selected });

    const saved = tool.loadState();
    if (saved && saved.item && saved.params) {
      const p = saved.params;
      selected = saved.item;
      input.value = selected.name;
      if (Array.from($('p-character').options).some((o) => o.value === String(p.character_id))) $('p-character').value = String(p.character_id);
      $('p-qty').value = p.qty || 1;
      $('p-me').value = p.me || 0;
      $('p-te').value = p.te || 0;
      $('p-reactions').checked = p.include_reactions !== false;
      tool.restoreChoices(saved);
      prefill(selected.id, false);
    }
    tool.compute();
  }

  // ===================================================================
  // Saved plan: progress tracking
  // ===================================================================

  // Column widths shared by the tracking tables (the item takes the rest).
  const TRACK_JOB_COLS = '<colgroup><col style="width:34px"><col><col style="width:74px"><col style="width:50px">' +
    '<col style="width:170px"><col style="width:84px"><col style="width:70px"><col style="width:120px"><col style="width:130px"></colgroup>';
  const TRACK_PURCHASE_COLS = '<colgroup><col style="width:34px"><col><col style="width:84px"><col style="width:190px">' +
    '<col style="width:78px"><col style="width:70px"><col style="width:84px"><col style="width:70px"></colgroup>';

  function progressBar(qty, target, done) {
    const ratio = target > 0 ? Math.min(1, qty / target) : 1;
    return '<div class="indus-progress"><div class="' + (done ? 'done' : '') + '" style="width:' + Math.round(ratio * 100) + '%"></div></div>';
  }

  // Tables of a saved plan: same sections as the production plan, with a
  // "Done" checkbox, a counter (runs started or quantity bought) and the
  // origin of the progress (SeAT detection or manual entry).
  class TrackingPlanView extends PlanView {
    constructor(jobsEl, purchasesEl, tabTitleEl, onProgress) {
      super('saved', jobsEl, purchasesEl, tabTitleEl);
      this.onProgress = onProgress;
    }

    render(data, state) {
      this.data = data;
      this.state = state;
      this.renderJobs();
      this.renderPurchases();
      const s = state.summary;
      this.tabTitleEl.textContent = t('plan_tab') + ' (' + t('tracking_tab_counts', {
        jobs_done: s.jobs_done, jobs_total: s.jobs_total, purchases_done: s.purchases_done, purchases_total: s.purchases_total,
      }) + ')';
    }

    counterCell(kind, typeId, p) {
      // Fixed-width columns (grid, not flex): the bar, the field and the
      // "/ target" text stay aligned from one row to the next whatever the
      // number of digits of the target.
      return '<div class="indus-counter-cell">' + progressBar(p.qty, p.target, p.done) +
        '<input type="number" min="0" class="form-control form-control-sm indus-counter js-qty" data-kind="' + kind +
        '" data-type="' + typeId + '" value="' + p.qty + '"><span class="indus-counter-target">/ ' + formatNumber(p.target) + '</span></div>';
    }

    trackCell(kind, typeId, p) {
      const reset = p.manual
        ? ' <button type="button" class="btn btn-xs btn-link p-0 js-reset" data-kind="' + kind + '" data-type="' + typeId +
          '" title="' + escapeHtml(t('back_to_auto')) + '"><i class="fas fa-undo"></i></button>'
        : '';
      if (p.manual) return '<span class="text-muted">' + escapeHtml(t('manual')) + '</span>' + reset;
      if (p.auto && p.auto.jobs) {
        return '<span class="indus-bp ok" title="' + escapeHtml(t('auto_help')) + '">' +
          escapeHtml(p.auto.active ? t('auto_jobs_active', { jobs: p.auto.jobs, active: p.auto.active }) : t('auto_jobs', { jobs: p.auto.jobs })) + '</span>';
      }
      return '<span class="text-muted">—</span>';
    }

    renderJobs() {
      const lines = this.data.jobs;
      const byRank = new Map();
      lines.forEach((l) => { if (!byRank.has(l.rank)) byRank.set(l.rank, []); byRank.get(l.rank).push(l); });

      let html = this.header(t('jobs_title'), t('jobs_title_help'));
      byRank.forEach((group, rank) => {
        const done = group.filter((l) => (this.state.jobs[l.type_id] || {}).done).length;
        const rows = group.map((l) => {
          const p = this.state.jobs[l.type_id] || { qty: 0, target: l.runs, done: false };
          return '<tr class="' + (p.done ? 'done' : '') + '">' +
            '<td><input type="checkbox" class="js-done" data-kind="job" data-type="' + l.type_id + '"' + (p.done ? ' checked' : '') + '></td>' +
            '<td class="name" title="' + escapeHtml(l.name) + '"><img src="' + icon(l.type_id, 32) + '" alt=""> ' + escapeHtml(l.name) + '</td>' +
            activityCell(l.activity) +
            '<td class="text-right">' + formatNumber(l.runs) + '</td>' +
            '<td>' + this.counterCell('job', l.type_id, p) + '</td>' +
            '<td class="text-right">' + (l.seconds ? formatDuration(l.seconds) : DASH) + '</td>' +
            '<td class="text-right" title="' + escapeHtml(formatIsk(l.job_cost)) + '">' + millionsCell(l.job_cost) + '</td>' +
            '<td title="' + escapeHtml(l.structure_name || '') + '">' + escapeHtml(l.structure_name || DASH) + '</td>' +
            '<td>' + this.trackCell('job', l.type_id, p) + '</td>' +
            '</tr>';
        }).join('');
        const table = '<table class="table table-sm table-striped mb-0 indus-plan-table indus-track-table' + (this.compact ? ' compact' : '') + '">' + TRACK_JOB_COLS + '<thead><tr>' +
          '<th>' + escapeHtml(t('col_done')) + '</th><th>' + escapeHtml(t('col_item')) + '</th><th>' + escapeHtml(t('col_activity')) + '</th>' +
          '<th class="text-right">' + escapeHtml(t('card_runs')) + '</th><th>' + escapeHtml(t('col_started')) + '</th>' +
          '<th class="text-right">' + escapeHtml(t('col_duration')) + '</th><th class="text-right">' + escapeHtml(t('col_cost')) + '</th>' +
          '<th>' + escapeHtml(t('col_structure')) + '</th><th>' + escapeHtml(t('col_tracking')) + '</th></tr></thead><tbody>' + rows + '</tbody></table>';
        html += this.section('rank-' + rank, rankTitle(rank), [t('done_count', { done, total: group.length })], table, rank === 0 ? '#6a5acd' : '#3b4a6b');
      });
      if (!lines.length) html += '<p class="text-muted">' + escapeHtml(t('no_plan_jobs')) + '</p>';
      this.jobsEl.innerHTML = html;
      this.bindTracking(this.jobsEl);
    }

    renderPurchases() {
      const lines = this.data.purchases;
      const remaining = () => lines.map((l) => {
        const p = this.state.purchases[l.type_id];
        return p && !p.done ? [l.name, Math.max(0, p.target - p.qty)] : null;
      }).filter((x) => x && x[1] > 0);

      let html = this.header(t('purchases_title'), '',
        '<button type="button" class="btn btn-xs btn-primary ml-auto js-copy-rest" title="' + escapeHtml(t('copy_rest_help')) + '">' + escapeHtml(t('copy_rest')) + '</button>');

      const byGroup = new Map();
      lines.forEach((l) => { if (!byGroup.has(l.group_name)) byGroup.set(l.group_name, []); byGroup.get(l.group_name).push(l); });
      byGroup.forEach((members, group) => {
        const done = members.filter((l) => (this.state.purchases[l.type_id] || {}).done).length;
        const rows = members.map((l) => {
          const p = this.state.purchases[l.type_id] || { qty: 0, target: l.quantity, done: false };
          const hasNow = Object.prototype.hasOwnProperty.call(l, 'stock_now');
          const stock = hasNow ? l.stock_now : (this.data.stock_known ? l.in_stock : null);
          return '<tr class="' + (p.done ? 'done' : '') + '">' +
            '<td><input type="checkbox" class="js-done" data-kind="purchase" data-type="' + l.type_id + '"' + (p.done ? ' checked' : '') + '></td>' +
            '<td class="name" title="' + escapeHtml(l.name) + '"><img src="' + icon(l.type_id, 32) + '" alt=""> ' + escapeHtml(l.name) + '</td>' +
            '<td class="text-right" title="' + escapeHtml(t('total_need', { qty: formatNumber(l.quantity) })) + '">' + formatNumber(p.target) + '</td>' +
            '<td>' + this.counterCell('purchase', l.type_id, p) + '</td>' +
            '<td class="text-right">' + (l.unit_price ? formatNumber(l.unit_price, 2) : DASH) + '</td>' +
            '<td class="text-right" title="' + escapeHtml(formatIsk(l.unit_price * p.target)) + '">' + millionsCell(l.unit_price * p.target) + '</td>' +
            '<td class="text-right" title="' + escapeHtml(l.stock_tooltip || '') + '">' + (stock === null || stock === undefined ? DASH : formatNumber(stock)) + '</td>' +
            '<td>' + this.trackCell('purchase', l.type_id, p) + '</td>' +
            '</tr>';
        }).join('');
        const table = '<table class="table table-sm table-striped mb-0 indus-plan-table indus-track-table' + (this.compact ? ' compact' : '') + '">' + TRACK_PURCHASE_COLS + '<thead><tr>' +
          '<th>' + escapeHtml(t('col_done')) + '</th><th>' + escapeHtml(t('col_material')) + '</th>' +
          '<th class="text-right" title="' + escapeHtml(t('col_to_buy_saved_help')) + '">' + escapeHtml(t('col_to_buy')) + '</th>' +
          '<th>' + escapeHtml(t('col_bought')) + '</th><th class="text-right">' + escapeHtml(t('col_unit_price')) + '</th>' +
          '<th class="text-right">' + escapeHtml(t('col_total')) + '</th><th class="text-right">' + escapeHtml(t('col_in_stock')) + '</th>' +
          '<th>' + escapeHtml(t('col_tracking')) + '</th></tr></thead><tbody>' + rows + '</tbody></table>';
        html += this.section('group-' + group, group, [t('done_count', { done, total: members.length })], table, '#3b4a6b');
      });
      if (!lines.length) html += '<p class="text-muted">' + escapeHtml(t('no_plan_purchases')) + '</p>';
      this.purchasesEl.innerHTML = html;
      this.bindTracking(this.purchasesEl);

      const copy = this.purchasesEl.querySelector('.js-copy-rest');
      if (copy) copy.addEventListener('click', () => {
        const rest = remaining();
        this.copy(rest.map(([name, q]) => ({ name, quantity: q, to_buy: q, in_stock: 0 })), copy, t('copy_rest'));
      });
    }

    bindTracking(root) {
      this.bindCommon(root);
      root.querySelectorAll('.js-done').forEach((chk) => chk.addEventListener('change', () => {
        this.onProgress(chk.dataset.kind, Number(chk.dataset.type), { done: chk.checked });
      }));
      root.querySelectorAll('.js-qty').forEach((input) => input.addEventListener('change', () => {
        this.onProgress(input.dataset.kind, Number(input.dataset.type), { qty: Math.max(0, Number(input.value) || 0) });
      }));
      root.querySelectorAll('.js-reset').forEach((btn) => btn.addEventListener('click', () => {
        this.onProgress(btn.dataset.kind, Number(btn.dataset.type), { reset: true });
      }));
    }
  }

  function savedPlan(cfg) {
    const $ = (id) => document.getElementById(id);
    let data = null;
    let state = null;

    const post = (url, body, method) => fetch(url, {
      method: method || 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
      body: JSON.stringify(body || {}),
    }).then((r) => r.json().then((json) => (r.ok ? json : Promise.reject(json.message || ('HTTP ' + r.status)))));

    const alerts = $('indus-alerts');
    const showAlert = (message, level) => {
      alerts.innerHTML = message ? '<div class="alert alert-' + (level || 'danger') + ' py-2">' + escapeHtml(message) + '</div>' : '';
    };

    // Progress of a card: job (produced item) or purchase.
    const progressFor = (card) => {
      if (!state) return null;
      const type = card.item.type_id;
      if (card.mode === MODE_PRODUCE && card.item.runs > 0) {
        const j = state.jobs[type];
        if (!j) return null;
        return { done: j.done, ratio: j.target ? j.qty / j.target : 1, text: j.done ? t('card_done') : (j.qty ? t('card_runs_started', { qty: j.qty, target: j.target }) : null) };
      }
      if (card.mode === MODE_BUY && card.rank > 0) {
        const p = state.purchases[type];
        if (!p) return null;
        return { done: p.done, ratio: p.target ? p.qty / p.target : 1, text: p.done ? t('card_bought') : (p.qty ? t('card_bought_qty', { qty: compact(p.qty) }) : null) };
      }
      return null;
    };

    const tree = new Tree($('indus-tree'), cfg.tool === 'reactions' ? LINK_COLOR_REACTION : LINK_COLOR_MANUFACTURING, {
      onChange: () => {}, onStructure: () => {}, readOnly: true, progressFor,
    });
    const view = new TrackingPlanView($('indus-jobs'), $('indus-purchases'), $('indus-plan-tab-title'), (kind, typeId, input) => {
      post(cfg.progressUrl, Object.assign({ kind, type_id: typeId }, input))
        .then((newState) => { state = newState; renderProgress(); })
        .catch((error) => showAlert(t('progress_failed', { error })));
    });

    function renderHeader() {
      const s = state.summary;
      const bar = $('plan-progress-bar');
      bar.style.width = Math.round(s.ratio * 100) + '%';
      bar.className = s.finished ? 'done' : '';
      $('plan-progress-text').textContent = s.finished
        ? t('plan_finished')
        : t('tracking_summary', { jobs_done: s.jobs_done, jobs_total: s.jobs_total, purchases_done: s.purchases_done, purchases_total: s.purchases_total }) +
          (s.active_jobs ? ' · ' + t('active_jobs', { count: s.active_jobs }) : '');
    }

    function renderProgress() {
      renderHeader();
      tree.refreshProgress();
      view.render({ jobs: data.jobs, purchases: data.purchases, stock_known: data.stock_known }, state);
    }

    function renderAll(json) {
      data = json;
      state = json.progress;
      tree.userModes = new Map(json.user_modes || []);
      tree.render(json.payload);
      updateSummary(tree, { stock_known: json.stock_known, purchases: json.purchases });
      renderProgress();
      if (json.plan.refreshed_at) $('plan-refreshed').textContent = formatDateTime(json.plan.refreshed_at);
      const fresh = state.summary.new_jobs;
      if (fresh) showAlert(t('new_jobs_detected', { count: fresh }), 'info');
    }

    fetch(cfg.dataUrl, { headers: { 'Accept': 'application/json' } })
      .then((r) => r.json())
      .then(renderAll)
      .catch((error) => showAlert(t('plan_load_failed', { error })));

    $('plan-refresh').addEventListener('click', () => {
      const btn = $('plan-refresh');
      btn.disabled = true;
      post(cfg.refreshUrl).then((json) => { renderAll(json); showAlert(t('refreshed'), 'success'); })
        .catch((error) => showAlert(t('refresh_failed', { error })))
        .finally(() => { btn.disabled = false; });
    });

    $('plan-rename').addEventListener('click', () => {
      const name = $('plan-name').value.trim();
      if (!name) { showAlert(t('plan_name_empty')); return; }
      post(cfg.renameUrl, { name }, 'PUT').then((json) => { document.title = 'SeAT | ' + json.name; showAlert(t('renamed'), 'success'); })
        .catch((error) => showAlert(t('rename_failed', { error })));
    });
  }

  window.IndusPlanner = { reactions, production, savedPlan };
})();
