'use strict';

import {setupEditor} from './modules/editor.js';
import {setupPageTree} from './modules/tree.js';
import {
  setupContentEnhancements,
  setupGlobalShortcuts,
  setupImageLightbox,
  setupInlineComments,
  setupNavigation,
  setupPlainUserAutocomplete,
  setupPresence,
  setupRestrictions,
  setupSearchSuggestions,
  setupTemplateSelection,
  setupUploadProgress,
} from './modules/ui.js';

document.addEventListener('DOMContentLoaded', () => {
  setupEditor();
  setupPlainUserAutocomplete();
  setupRestrictions();
  setupUploadProgress();
  setupContentEnhancements();
  setupSearchSuggestions();
  setupInlineComments();
  setupGlobalShortcuts();
  setupImageLightbox();
  setupPageTree();
  setupPresence();
  setupTemplateSelection();
  setupNavigation();
});
