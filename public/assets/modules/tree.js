'use strict';

function childUrl(tree, parentId, offset = 0) {
  const url = new URL(tree.dataset.treeChildrenUrl, window.location.origin);
  if (parentId) url.searchParams.set('parent_id', String(parentId));
  url.searchParams.set('offset', String(offset));
  url.searchParams.set('limit', '50');
  return url;
}

function buildNode(tree, item) {
  const li = document.createElement('li');
  li.className = 'page-tree-item';
  li.dataset.pageId = String(item.id);
  li.dataset.parentId = String(item.parent_id || 0);
  if (tree.dataset.treeManage === '1') li.draggable = true;

  const line = document.createElement('div');
  line.className = 'page-tree-line';
  if (item.has_children) {
    const expand = document.createElement('button');
    expand.type = 'button';
    expand.className = 'tree-expand';
    expand.dataset.treeExpand = '1';
    expand.setAttribute('aria-expanded', 'false');
    expand.setAttribute('aria-label', 'Rozwiń podstrony');
    expand.textContent = '›';
    line.append(expand);
  } else {
    const spacer = document.createElement('span');
    spacer.className = 'tree-expand-spacer';
    line.append(spacer);
  }

  const link = document.createElement('a');
  link.href = tree.dataset.pageUrlTemplate.replace('__ID__', String(item.id));
  link.textContent = String(item.title || 'Strona');
  line.append(link);
  li.append(line);
  return li;
}

async function loadPage(tree, parentId, container, offset = 0) {
  const response = await fetch(childUrl(tree, parentId, offset), {
    credentials: 'same-origin',
    headers: {'X-Requested-With': 'XMLHttpRequest'},
  });
  if (!response.ok) throw new Error('Tree request failed');
  const data = await response.json();
  let list = container.querySelector(':scope > ul.page-tree');
  if (!list) {
    list = document.createElement('ul');
    list.className = 'page-tree';
    container.append(list);
  }
  for (const item of data.items || []) list.append(buildNode(tree, item));

  container.querySelector(':scope > button.tree-more')?.remove();
  if (data.has_more && data.next_offset !== null) {
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'link-button tree-more';
    more.textContent = 'Pokaż więcej';
    more.dataset.parentId = String(parentId || 0);
    more.dataset.offset = String(data.next_offset);
    container.append(more);
  }
}

export function setupPageTree() {
  const tree = document.querySelector('[data-page-tree]');
  if (!tree) return;
  let dragged = null;

  tree.addEventListener('click', async event => {
    const expand = event.target.closest('[data-tree-expand]');
    if (expand && tree.contains(expand)) {
      const item = expand.closest('.page-tree-item');
      if (!item) return;
      const existing = item.querySelector(':scope > .tree-children');
      if (existing && !existing.hidden) {
        existing.hidden = true;
        expand.setAttribute('aria-expanded', 'false');
        expand.textContent = '›';
        return;
      }
      const container = existing || document.createElement('div');
      container.className = 'tree-children';
      if (!existing) item.append(container);
      container.hidden = false;
      expand.disabled = true;
      try {
        if (!container.dataset.loaded) {
          await loadPage(tree, Number(item.dataset.pageId), container, 0);
          container.dataset.loaded = '1';
        }
        expand.setAttribute('aria-expanded', 'true');
        expand.textContent = '⌄';
      } catch {
        container.textContent = 'Nie udało się pobrać podstron.';
      } finally {
        expand.disabled = false;
      }
      return;
    }

    const more = event.target.closest('.tree-more');
    if (more && tree.contains(more)) {
      more.disabled = true;
      try {
        await loadPage(tree, Number(more.dataset.parentId || 0), more.parentElement, Number(more.dataset.offset || 0));
      } catch {
        more.disabled = false;
        more.textContent = 'Ponów pobieranie';
      }
    }
  });

  if (tree.dataset.treeManage !== '1') return;
  const moveTemplate = tree.dataset.treeMoveTemplate;
  const csrf = tree.dataset.csrf;
  const space = tree.dataset.spaceId;
  const move = async parentId => {
    if (!dragged) return false;
    const url = moveTemplate.replace('__ID__', dragged.dataset.pageId);
    const body = new URLSearchParams({_csrf: csrf, space_id: space, parent_id: String(parentId || 0), sort_order: '0'});
    const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin', body: body.toString()});
    return response.ok;
  };

  tree.addEventListener('dragstart', event => {
    const item = event.target.closest('.page-tree-item[draggable="true"]');
    if (!item) return;
    dragged = item;
    item.classList.add('dragging');
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
  });
  tree.addEventListener('dragend', () => {
    dragged?.classList.remove('dragging');
    tree.querySelectorAll('.drag-target').forEach(node => node.classList.remove('drag-target'));
    dragged = null;
  });
  tree.addEventListener('dragover', event => {
    if (!dragged) return;
    const target = event.target.closest('.page-tree-item,.tree-root-drop');
    if (!target || target === dragged) return;
    event.preventDefault();
    target.classList.add('drag-target');
  });
  tree.addEventListener('dragleave', event => event.target.closest('.drag-target')?.classList.remove('drag-target'));
  tree.addEventListener('drop', async event => {
    if (!dragged) return;
    const target = event.target.closest('.page-tree-item,.tree-root-drop');
    if (!target) return;
    event.preventDefault();
    target.classList.remove('drag-target');
    const parentId = target.classList.contains('tree-root-drop') ? 0 : Number(target.dataset.pageId || 0);
    if (Number(dragged.dataset.pageId) === parentId) return;
    if (await move(parentId)) window.location.reload();
  });
}
