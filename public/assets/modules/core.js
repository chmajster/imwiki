'use strict';

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

export const debounce = (fn, ms = 180) => {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), ms);
  };
};

const userEndpoint = () => document.body?.dataset.userAutocompleteUrl || '';

export async function fetchUsers(query) {
  if (!query || !userEndpoint()) return [];
  const url = new URL(userEndpoint(), window.location.origin);
  url.searchParams.set('q', query);
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: {'X-Requested-With': 'XMLHttpRequest'},
  });
  if (!response.ok) return [];
  const data = await response.json();
  return Array.isArray(data.items) ? data.items : [];
}

export function selectionRangeInside(root) {
  const selection = window.getSelection();
  if (!selection || selection.rangeCount === 0) return null;
  const range = selection.getRangeAt(0);
  if (!root.contains(range.commonAncestorContainer)) return null;
  return range;
}

export function placeCaretAfter(node) {
  const range = document.createRange();
  range.setStartAfter(node);
  range.collapse(true);
  const selection = window.getSelection();
  selection?.removeAllRanges();
  selection?.addRange(range);
}
