'use strict';

import {$, $$, debounce, fetchUsers} from './core.js';

export function setupPlainUserAutocomplete() {
  $$('input[data-user-input]').forEach(input => {
    const wrap = input.parentElement;
    wrap?.classList.add('autocomplete-wrap');
    const menu = document.createElement('div');
    menu.className = 'mention-menu input-menu';
    menu.hidden = true;
    wrap?.append(menu);
    const close = () => { menu.hidden = true; menu.replaceChildren(); };
    const refresh = debounce(async () => {
      const query = input.value.replace(/^@/, '').trim();
      if (!query) { close(); return; }
      const items = await fetchUsers(query);
      menu.replaceChildren();
      for (const user of items) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'mention-option';
        button.textContent = '@' + user.username + ' — ' + user.label;
        button.addEventListener('mousedown', event => {
          event.preventDefault();
          input.value = user.username;
          close();
        });
        menu.append(button);
      }
      menu.hidden = items.length === 0;
    }, 160);
    input.addEventListener('input', refresh);
    input.addEventListener('blur', () => setTimeout(close, 120));
  });
}

export function setupRestrictions() {
  const form = $('[data-restriction-form]');
  if (!form) return;
  const type = $('#subject-type', form);
  if (!type) return;
  const sync = () => {
    const selected = type.value;
    $$('[data-subject-wrap]', form).forEach(wrap => { wrap.hidden = wrap.dataset.subjectWrap !== selected; });
    $$('[data-subject]', form).forEach(subject => {
      const active = subject.dataset.subject === selected;
      subject.disabled = !active;
      if (active) subject.name = subject.dataset.name || 'subject_id'; else subject.removeAttribute('name');
    });
  };
  type.addEventListener('change', sync);
  sync();
}

export function setupUploadProgress() {
  const form = $('[data-upload-form]');
  if (!form) return;
  const state = $('[data-upload-state]', form);
  form.addEventListener('submit', event => {
    if (!window.XMLHttpRequest || !state) return;
    event.preventDefault();
    const xhr = new XMLHttpRequest();
    xhr.open('POST', form.action);
    xhr.upload.addEventListener('progress', progress => {
      if (progress.lengthComputable) state.textContent = 'Wysyłanie ' + Math.round(progress.loaded / progress.total * 100) + '%';
    });
    xhr.addEventListener('load', () => {
      if (xhr.status >= 200 && xhr.status < 400) {
        state.textContent = 'Gotowe';
        window.location.reload();
      } else state.textContent = 'Błąd wysyłania';
    });
    xhr.addEventListener('error', () => { state.textContent = 'Błąd wysyłania'; });
    xhr.send(new FormData(form));
  });
}

export function setupContentEnhancements() {
  $$('[data-page-content] pre').forEach(pre => {
    if (pre.parentElement?.classList.contains('code-wrap')) return;
    const wrap = document.createElement('div');
    wrap.className = 'code-wrap';
    pre.before(wrap);
    wrap.append(pre);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'copy-code';
    button.textContent = 'Kopiuj kod';
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(pre.innerText);
        button.textContent = 'Skopiowano';
        setTimeout(() => { button.textContent = 'Kopiuj kod'; }, 1200);
      } catch { button.textContent = 'Nie udało się'; }
    });
    wrap.append(button);
  });

  const used = new Map();
  $$('[data-page-content] h1,[data-page-content] h2,[data-page-content] h3,[data-page-content] h4,[data-page-content] h5,[data-page-content] h6').forEach(heading => {
    const base = (heading.textContent || 'sekcja').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'sekcja';
    const count = (used.get(base) || 0) + 1;
    used.set(base, count);
    heading.id = count === 1 ? base : base + '-' + count;
    const anchor = document.createElement('a');
    anchor.className = 'heading-anchor';
    anchor.href = '#' + heading.id;
    anchor.setAttribute('aria-label', 'Kopiuj link do nagłówka');
    anchor.textContent = '#';
    anchor.addEventListener('click', async event => {
      event.preventDefault();
      history.replaceState(null, '', '#' + heading.id);
      try { await navigator.clipboard.writeText(window.location.href); } catch {}
    });
    heading.append(anchor);
  });
}

export function setupSearchSuggestions() {
  const form = $('[data-search-form]');
  const input = form?.querySelector('input[name="q"]');
  const menu = form?.querySelector('[data-search-suggestions]');
  if (!form || !input || !menu) return;
  let items = [], active = -1, sequence = 0;
  const close = () => { menu.hidden = true; menu.replaceChildren(); items = []; active = -1; };
  const render = () => {
    menu.replaceChildren();
    items.forEach((item, index) => {
      const link = document.createElement('a');
      link.href = item.url;
      link.className = 'search-suggestion' + (index === active ? ' active' : '');
      const kind = document.createElement('span');
      kind.className = 'suggestion-kind';
      kind.textContent = item.kind;
      const text = document.createElement('span');
      const strong = document.createElement('strong');
      strong.textContent = item.title;
      const small = document.createElement('small');
      small.textContent = item.subtitle || '';
      text.append(strong, small);
      link.append(kind, text);
      menu.append(link);
    });
    menu.hidden = items.length === 0;
  };
  const refresh = debounce(async () => {
    const query = input.value.trim();
    if (!query) { close(); return; }
    const current = ++sequence;
    try {
      const url = new URL(form.dataset.suggestionsUrl, window.location.origin);
      url.searchParams.set('q', query);
      const response = await fetch(url, {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
      if (!response.ok) throw new Error();
      const data = await response.json();
      if (current !== sequence) return;
      items = Array.isArray(data.items) ? data.items : [];
      active = -1;
      render();
    } catch { close(); }
  }, 140);
  input.addEventListener('input', refresh);
  input.addEventListener('focus', () => { if (input.value.trim()) refresh(); });
  input.addEventListener('keydown', event => {
    if (menu.hidden || !items.length) return;
    if (event.key === 'ArrowDown') { event.preventDefault(); active = (active + 1) % items.length; render(); }
    else if (event.key === 'ArrowUp') { event.preventDefault(); active = (active - 1 + items.length) % items.length; render(); }
    else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); window.location.href = items[active].url; }
    else if (event.key === 'Escape') { event.preventDefault(); close(); }
  });
  document.addEventListener('click', event => { if (!form.contains(event.target)) close(); });
}

export function setupInlineComments() {
  const trigger = document.querySelector('[data-inline-comment-trigger]');
  const form = document.querySelector('[data-inline-comment-form]');
  if (!trigger || !form) return;
  const quote = form.querySelector('textarea[name="quote"]');
  const body = form.querySelector('textarea[name="body"]');
  const content = document.querySelector('[data-page-content]');
  trigger.addEventListener('click', () => {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || !content || !quote || !body) return;
    const range = selection.getRangeAt(0);
    const node = range.commonAncestorContainer.nodeType === Node.TEXT_NODE ? range.commonAncestorContainer.parentElement : range.commonAncestorContainer;
    if (!node || !content.contains(node)) return;
    const text = selection.toString().trim().slice(0, 1000);
    if (!text) return;
    quote.value = text;
    form.hidden = false;
    body.focus();
  });
  form.querySelector('[data-inline-comment-cancel]')?.addEventListener('click', () => {
    form.hidden = true;
    if (quote) quote.value = '';
    if (body) body.value = '';
  });
}

export function setupImageLightbox() {
  const dialog = document.querySelector('[data-image-lightbox]');
  if (!dialog) return;
  const image = dialog.querySelector('[data-lightbox-image]');
  const title = dialog.querySelector('[data-lightbox-title]');
  const download = dialog.querySelector('[data-lightbox-download]');
  let scale = 1;
  const apply = () => { if (image) image.style.transform = `scale(${scale})`; };
  document.querySelectorAll('[data-lightbox-src]').forEach(button => button.addEventListener('click', () => {
    scale = 1;
    if (image) image.src = button.dataset.lightboxSrc;
    if (title) title.textContent = button.dataset.lightboxName || 'Podgląd obrazu';
    if (download) { download.href = button.dataset.lightboxSrc; download.setAttribute('download', button.dataset.lightboxName || 'image'); }
    apply();
    dialog.showModal();
  }));
  dialog.querySelector('[data-lightbox-close]')?.addEventListener('click', () => dialog.close());
  dialog.querySelector('[data-lightbox-zoom-in]')?.addEventListener('click', () => { scale = Math.min(4, scale + .25); apply(); });
  dialog.querySelector('[data-lightbox-zoom-out]')?.addEventListener('click', () => { scale = Math.max(.25, scale - .25); apply(); });
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
}

export function setupGlobalShortcuts() {
  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      const search = $('.topbar .search input');
      search?.focus();
      search?.select();
    }
    if (event.key === 'Escape') {
      $$('[data-mention-menu],.input-menu').forEach(menu => { menu.hidden = true; });
      document.querySelector('[data-image-lightbox][open]')?.close();
      document.querySelectorAll('.nav-menu[open]').forEach(menu => menu.removeAttribute('open'));
    }
  });
}

export function setupPresence() {
  const element = document.querySelector('[data-presence]');
  if (!element) return;
  const url = element.dataset.url;
  const csrf = element.dataset.csrf;
  let stopped = false;
  const beat = async () => {
    if (stopped) return;
    try {
      const body = new URLSearchParams({_csrf: csrf});
      const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin', body: body.toString()});
      if (!response.ok) return;
      const data = await response.json();
      const users = Array.isArray(data.users) ? data.users : [];
      element.textContent = users.length ? users.map(user => user.name || ('@' + user.username)).join(', ') + ' również edytuje tę stronę.' : '';
      element.hidden = users.length === 0;
    } catch {}
  };
  beat();
  const timer = setInterval(beat, 20000);
  window.addEventListener('pagehide', () => { stopped = true; clearInterval(timer); });
}

export function setupTemplateSelection() {
  const select = document.querySelector('#template-select');
  const editor = document.querySelector('#rich-editor');
  if (!select || !editor) return;
  select.addEventListener('change', () => {
    const option = select.options[select.selectedIndex];
    if (!option || !option.dataset.content || editor.innerHTML.trim() !== '') return;
    try {
      const bytes = Uint8Array.from(atob(option.dataset.content), char => char.charCodeAt(0));
      editor.innerHTML = new TextDecoder('utf-8').decode(bytes);
      editor.dispatchEvent(new Event('input', {bubbles: true}));
    } catch {}
  });
}

export function setupNavigation() {
  document.addEventListener('click', event => {
    document.querySelectorAll('.nav-menu[open]').forEach(menu => {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });
}
