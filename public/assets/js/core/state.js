'use strict';

// Shared app state. Declared as classic-script top-level `let` so every
// subsequent script in the page can read/assign these globals by name.
const API = 'api.php';

let csrf = '', currentPage = 'dashboard';
let productsPage = 1, ordersPage = 1, productsStatus = '', ordersStatus = '';
let productsCompareBucket = 'not_synced';
let productsCompareRows = [];
let currentCategoryProductsPsCategoryId = 0;
let currentCategoryProductsRows = [];
let currentCategorySuggestionsByPsId = {};
let jobPollTimer = null, settingsCache = [];
let categoryMappingsCache = [];
let categoryMappingsDraft = {};
let attributeMappingsCache = [];
let attributeMappingsDraft = {};
let schedulerDraft = {};
let ordersCache = [];
let currentOrderEditId = 0;
let deletedOrderItemIds = [];
let orderItemProductMap = {};
let currentProductDetail = null;
let productDetailLoadToken = 0;
let syncPid = 0;
let selectedRunIds = [];
let logsPage = 1;
const logsPerPage = 50;
