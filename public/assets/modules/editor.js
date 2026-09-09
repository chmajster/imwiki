'use strict';

import {$, $$, debounce, fetchUsers, placeCaretAfter, selectionRangeInside} from './core.js';

function notifyInput(editor) {
  editor.dispatchEvent(new Event('input', {bubbles: true}));
  editor.focus();
}

function insertNodeAtSelection(editor, node) {
  const range = selectionRangeInside(editor);
  if (!range) {
    editor.append(node);
    placeCaretAfter(node);
    notifyInput(editor);
    return;
  }
  range.deleteContents();
  range.insertNode(node);
  placeCaretAfter(node);
  notifyInput(editor);
}

function insertHtml(editor, html) {
  const template = document.createElement('template');
  template.innerHTML = html;
  const fragment = template.content;
  const last = fragment.lastChild;
  const range = selectionRangeInside(editor);
  if (!range) {
    editor.append(fragment);
    if (last) placeCaretAfter(last);
    notifyInput(editor);
    return;
  }
  range.deleteContents();
  range.insertNode(fragment);
  if (last) placeCaretAfter(last);
  notifyInput(editor);
}

function wrapSelection(editor, tagName) {
  const range = selectionRangeInside(editor);
  const element = document.createElement(tagName);
  if (!range) {
    element.append(document.createTextNode('\u200b'));
    editor.append(element);
    placeCaretAfter(element);
    notifyInput(editor);
    return;
  }
  if (range.collapsed) {
    const marker = document.createTextNode('\u200b');
    element.append(marker);
    range.insertNode(element);
    const caret = document.createRange();
    caret.setStart(marker, 1);
    caret.collapse(true);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(caret);
  } else {
    const extracted = range.extractContents();
    element.append(extracted);
    range.insertNode(element);
    placeCaretAfter(element);
  }
  notifyInput(editor);
}

function formatBlock(editor, tagName) {
  const allowed = ['h2', 'h3', 'blockquote', 'pre'];
  if (!allowed.includes(tagName)) return;
  const range = selectionRangeInside(editor);
  const block = document.createElement(tagName);
  if (!range || range.collapsed) {
    block.append(document.createElement('br'));
    if (range) range.insertNode(block); else editor.append(block);
    placeCaretAfter(block);
    notifyInput(editor);
    return;
  }
  block.append(range.extractContents());
  range.insertNode(block);
  placeCaretAfter(block);
  notifyInput(editor);
}

function makeList(editor, ordered) {
  const range = selectionRangeInside(editor);
  const list = document.createElement(ordered ? 'ol' : 'ul');
  const text = range ? range.toString() : '';
  const rows = text.split(/\r?\n/).map(row => row.trim()).filter(Boolean);
  if (rows.length) {
    for (const row of rows) {
      const item = document.createElement('li');
      item.textContent = row;
      list.append(item);
    }
    range.deleteContents();
    range.insertNode(list);
  } else {
    const item = document.createElement('li');
    item.append(document.createElement('br'));
    list.append(item);
    if (range) range.insertNode(list); else editor.append(list);
  }
  placeCaretAfter(list);
  notifyInput(editor);
}

function applyCommand(editor, command) {
  switch (command) {
    case 'bold': wrapSelection(editor, 'strong'); break;
    case 'italic': wrapSelection(editor, 'em'); break;
    case 'underline': wrapSelection(editor, 'u'); break;
    case 'insertUnorderedList': makeList(editor, false); break;
    case 'insertOrderedList': makeList(editor, true); break;
  }
}

function macroNode(name, selectedText) {
  if (['info', 'warning', 'success', 'error'].includes(name)) {
    const box = document.createElement('div');
    box.className = `macro macro-${name}`;
    const strong = document.createElement('strong');
    strong.textContent = name[0].toUpperCase() + name.slice(1);
    const p = document.createElement('p');
    p.textContent = selectedText || 'Treść';
    box.append(strong, p);
    return box;
  }
  if (name === 'expand') {
    const details = document.createElement('details');
    details.className = 'macro macro-expand';
    const summary = document.createElement('summary');
    summary.textContent = selectedText || 'Rozwiń';
    const p = document.createElement('p');
    p.textContent = 'Treść';
    details.append(summary, p);
    return details;
  }
  if (name === 'mermaid') {
    const pre = document.createElement('pre');
    pre.className = 'mermaid';
    pre.textContent = selectedText || 'flowchart TD\n  A[Start] --> B[Koniec]';
    return pre;
  }
  if (['toc', 'children', 'recently-updated', 'page-properties', 'task-list'].includes(name)) {
    const p = document.createElement('p');
    p.textContent = `{{${name}}}`;
    return p;
  }
  return null;
}

function currentMentionInEditor(editor) {
  const selection = window.getSelection();
  if (!selection || selection.rangeCount === 0 || !editor.contains(selection.anchorNode)) return null;
  const node = selection.anchorNode;
  if (!node || node.nodeType !== Node.TEXT_NODE) return null;
  const before = (node.textContent || '').slice(0, selection.anchorOffset);
  const match = before.match(/(?:^|\s)@([A-Za-z0-9._-]{1,100})$/);
  if (!match) return null;
  return {node, start: selection.anchorOffset - match[1].length - 1, end: selection.anchorOffset, query: match[1]};
}

function insertEditorMention(context, username, editor) {
  const range = document.createRange();
  range.setStart(context.node, context.start);
  range.setEnd(context.node, context.end);
  range.deleteContents();
  const text = document.createTextNode('@' + username + ' ');
  range.insertNode(text);
  placeCaretAfter(text);
  notifyInput(editor);
}

function setupRichMentions(editor, menu) {
  if (!menu) return;
  let sequence = 0;
  const close = () => { menu.hidden = true; menu.replaceChildren(); };
  const refresh = debounce(async () => {
    const context = currentMentionInEditor(editor);
    if (!context) { close(); return; }
    const current = ++sequence;
    const items = await fetchUsers(context.query);
    if (current !== sequence) return;
    if (!items.length) { close(); return; }
    menu.replaceChildren();
    items.forEach(user => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'mention-option';
      button.textContent = '@' + user.username + ' — ' + user.label;
      button.addEventListener('mousedown', event => {
        event.preventDefault();
        insertEditorMention(currentMentionInEditor(editor) || context, user.username, editor);
        close();
      });
      menu.append(button);
    });
    menu.hidden = false;
  }, 160);
  editor.addEventListener('input', refresh);
  editor.addEventListener('blur', () => setTimeout(close, 120));
}

function setupEditorUploads(editor, form) {
  const url = editor.dataset.attachmentUploadUrl;
  if (!url) return;
  const csrf = form.querySelector('[name="_csrf"]')?.value || '';
  const upload = async files => {
    const list = Array.from(files || []).filter(file => file && file.size > 0).slice(0, 20);
    if (!list.length) return;
    const data = new FormData();
    data.set('_csrf', csrf);
    for (const file of list) data.append('attachments[]', file, file.name || 'image.png');
    editor.classList.add('uploading');
    try {
      const response = await fetch(url, {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: data});
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Upload failed');
      for (const attachment of payload.attachments || []) {
        const paragraph = document.createElement('p');
        if (attachment.preview_url) {
          const image = document.createElement('img');
          image.src = String(attachment.preview_url);
          image.alt = String(attachment.name || 'załącznik');
          paragraph.append(image);
        } else {
          const link = document.createElement('a');
          link.href = String(attachment.download_url || '#');
          link.textContent = String(attachment.name || 'załącznik');
          paragraph.append(link);
        }
        insertNodeAtSelection(editor, paragraph);
      }
    } catch (error) {
      console.error(error);
      const state = document.querySelector('#autosave-state');
      if (state) state.textContent = 'Błąd wysyłania pliku';
    } finally {
      editor.classList.remove('uploading');
    }
  };
  editor.addEventListener('dragover', event => {
    if (Array.from(event.dataTransfer?.types || []).includes('Files')) {
      event.preventDefault();
      editor.classList.add('drag-target');
    }
  });
  editor.addEventListener('dragleave', () => editor.classList.remove('drag-target'));
  editor.addEventListener('drop', event => {
    const files = event.dataTransfer?.files;
    if (files?.length) {
      event.preventDefault();
      editor.classList.remove('drag-target');
      upload(files);
    }
  });
  editor.addEventListener('paste', event => {
    const files = [];
    for (const item of Array.from(event.clipboardData?.items || [])) {
      if (item.kind === 'file') {
        const file = item.getAsFile();
        if (file && file.type.startsWith('image/')) files.push(file);
      }
    }
    if (files.length) {
      event.preventDefault();
      upload(files);
    }
  });
}

export function setupEditor() {
  const form = $('.editor-form');
  const editor = $('#rich-editor');
  const field = $('#content-field');
  if (!form || !editor || !field) return;

  const sync = () => { field.value = editor.innerHTML; };
  form.addEventListener('submit', sync);
  $$('[data-cmd]', form).forEach(button => button.addEventListener('click', () => applyCommand(editor, button.dataset.cmd || '')));
  $$('[data-block]', form).forEach(button => button.addEventListener('click', () => formatBlock(editor, button.dataset.block || '')));
  $$('[data-macro]', form).forEach(button => button.addEventListener('click', () => {
    const name = button.dataset.macro || '';
    const selected = (window.getSelection()?.toString() || '').trim();
    const node = macroNode(name, selected);
    if (!node) return;
    insertNodeAtSelection(editor, node);
    const spacer = document.createElement('p');
    spacer.append(document.createElement('br'));
    node.after(spacer);
    placeCaretAfter(spacer);
  }));

  const fullscreen = $('[data-fullscreen]', form);
  fullscreen?.addEventListener('click', () => form.classList.toggle('fullscreen'));

  const url = editor.dataset.autosaveUrl;
  const state = $('#autosave-state');
  let timer = null;
  if (url && state) {
    const save = async () => {
      sync();
      state.textContent = 'Zapisywanie...';
      const data = new URLSearchParams();
      data.set('_csrf', $('[name="_csrf"]', form)?.value || '');
      data.set('title', $('#page-title')?.value || '');
      data.set('content', field.value);
      data.set('base_version', $('#base_version')?.value || '0');
      try {
        const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'}, body: data.toString(), credentials: 'same-origin'});
        if (!response.ok) throw new Error();
        state.textContent = 'Zapisano';
      } catch {
        state.textContent = 'Błąd zapisu';
      }
    };
    const schedule = () => {
      clearTimeout(timer);
      state.textContent = 'Niezapisane zmiany';
      timer = setTimeout(save, 1200);
    };
    editor.addEventListener('input', schedule);
    $('#page-title')?.addEventListener('input', schedule);
  }

  document.addEventListener('keydown', event => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      sync();
      form.requestSubmit();
    }
  });

  setupRichMentions(editor, $('[data-mention-menu]'));
  setupEditorUploads(editor, form);
}
