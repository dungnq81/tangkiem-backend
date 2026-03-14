{{-- Role-specific JS: Search, Expand/Collapse, Badges --}}
{{-- Only executes on pages with .fi-page-shield-roles class --}}
<script>
	document.addEventListener('DOMContentLoaded', () => {
		const pageEl = document.querySelector('.fi-page-shield-roles');
		if (!pageEl) return;

		const maxAttempts = 50;
		let attempts = 0;

		const init = () => {
			attempts++;
			// Filament 5: tabs container is .fi-sc-tabs
			const tabsContainer = pageEl.querySelector('.fi-sc-tabs');
			if (!tabsContainer && attempts < maxAttempts) {
				setTimeout(init, 250);
				return;
			}
			if (!tabsContainer) return;

			setupToolbar(tabsContainer, pageEl);
		};

		setTimeout(init, 500);
	});

	function setupToolbar(tabsContainer, pageEl) {
		// Filament 5: tab panels are children of .fi-sc-tabs (not wrapped in [role=tabpanel])
		// The resources tab content is in the first Tab component's content area
		// Structure: .fi-sc-tabs > .fi-tabs (nav) + children (tab contents wrapped in divs or direct)
		const tabNav = tabsContainer.querySelector('.fi-tabs');
		if (!tabNav) return;

		// Find the first tab's content area (contains the resources grid)
		// In Filament 5, tab contents are sibling elements after .fi-tabs
		const tabContents = [];
		let sibling = tabNav.nextElementSibling;
		while (sibling) {
			tabContents.push(sibling);
			sibling = sibling.nextElementSibling;
		}
		const resourcesPanel = tabContents[0];
		if (!resourcesPanel) return;

		// ── Create toolbar ────────────────────────────────────────────────
		const toolbar = document.createElement('div');
		toolbar.className = 'role-permissions-toolbar';
		toolbar.innerHTML = `
		<div class="role-search-wrapper">
			<svg class="role-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
			</svg>
			<input type="text" class="role-search-input" placeholder="Tìm kiếm tài nguyên... (Ctrl+F)" autocomplete="off" />
		</div>
		<span class="role-match-count"></span>
		<button type="button" class="role-toolbar-btn" data-action="expand">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
			</svg>
			Mở tất cả
		</button>
		<button type="button" class="role-toolbar-btn" data-action="collapse">
			<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
				<path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9H4.5M9 9 3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5 5.25 5.25" />
			</svg>
			Thu gọn
		</button>
	`;

		// Insert toolbar between tab nav and first tab content
		tabNav.parentNode.insertBefore(toolbar, tabNav.nextSibling);

		// ── Show toolbar only on Resources tab (first tab) ────────────────
		const tabButtons = tabNav.querySelectorAll('.fi-tabs-item');
		const updateToolbarVisibility = () => {
			// Filament 5 uses .fi-active class on active tab
			const activeTab = tabNav.querySelector('.fi-tabs-item.fi-active');
			const allTabs = Array.from(tabButtons);
			const activeIndex = allTabs.indexOf(activeTab);
			// Show toolbar only when Resources tab (first tab, index 0) is active
			toolbar.style.display = (activeIndex === 0) ? '' : 'none';
		};

		// Listen for tab clicks
		tabButtons.forEach(btn => {
			btn.addEventListener('click', () => {
				// Delay to let Filament update active state
				setTimeout(updateToolbarVisibility, 50);
			});
		});

		// Initial state
		updateToolbarVisibility();

		// ── No-results message ────────────────────────────────────────────
		const noResults = document.createElement('div');
		noResults.className = 'role-no-results';
		noResults.innerHTML = `
		<svg class="role-no-results-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
			<path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
		</svg>
		<p>Không tìm thấy tài nguyên nào phù hợp</p>
	`;
		resourcesPanel.appendChild(noResults);

		// ── Helpers ────────────────────────────────────────────────────────
		// Filament 5: sections are .fi-sc-section > section.fi-section
		// We target .fi-sc-section (the wrapper) for show/hide
		const getAllSectionWrappers = () => {
			return resourcesPanel.querySelectorAll('.fi-sc-section');
		};

		// ── Search ────────────────────────────────────────────────────────
		const searchInput = toolbar.querySelector('.role-search-input');
		const matchCountEl = toolbar.querySelector('.role-match-count');

		let debounceTimer;
		searchInput.addEventListener('input', () => {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(() => filterSections(searchInput.value), 120);
		});

		// Ctrl+F shortcut
		document.addEventListener('keydown', (e) => {
			if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
				if (document.querySelector('.fi-page-shield-roles') && searchInput) {
					e.preventDefault();
					searchInput.focus();
					searchInput.select();
				}
			}
			if (e.key === 'Escape' && document.activeElement === searchInput) {
				searchInput.value = '';
				filterSections('');
				searchInput.blur();
			}
		});

		function filterSections(query) {
			const wrappers = getAllSectionWrappers();
			const q = query.trim().toLowerCase();

			if (!q) {
				wrappers.forEach(w => w.classList.remove('role-hidden'));
				matchCountEl.textContent = '';
				noResults.classList.remove('visible');
				return;
			}

			let visible = 0;
			wrappers.forEach(wrapper => {
				const heading = wrapper.querySelector('.fi-section-header-heading');
				const desc = wrapper.querySelector('.fi-section-header-description');
				const text = ((heading?.textContent || '') + ' ' + (desc?.textContent || '')).toLowerCase();

				if (text.includes(q)) {
					wrapper.classList.remove('role-hidden');
					visible++;
				} else {
					wrapper.classList.add('role-hidden');
				}
			});

			matchCountEl.textContent = `${visible} / ${wrappers.length}`;
			noResults.classList.toggle('visible', visible === 0);
		}

		// ── Badges: permission count on each section ──────────────────────
		addPermissionBadges(getAllSectionWrappers());

		// ── Expand / Collapse All ─────────────────────────────────────────
		toolbar.querySelector('[data-action="expand"]').addEventListener('click', () => {
			toggleAllSections(resourcesPanel, false);
		});

		toolbar.querySelector('[data-action="collapse"]').addEventListener('click', () => {
			toggleAllSections(resourcesPanel, true);
		});
	}

	function addPermissionBadges(wrappers) {
		wrappers.forEach(wrapper => {
			const checkboxes = wrapper.querySelectorAll('input[type="checkbox"]');
			const checked = wrapper.querySelectorAll('input[type="checkbox"]:checked');
			const heading = wrapper.querySelector('.fi-section-header-heading');

			if (heading && checkboxes.length > 0 && !heading.querySelector('.role-section-badge')) {
				const badge = document.createElement('span');
				const active = checked.length > 0;
				badge.className = `role-section-badge ${active ? 'role-section-badge--active' : 'role-section-badge--inactive'}`;
				badge.textContent = `${checked.length}/${checkboxes.length}`;
				badge.title = `${checked.length} quyền đã bật / ${checkboxes.length} tổng`;
				heading.appendChild(document.createTextNode(' '));
				heading.appendChild(badge);

				// Live update badges on checkbox change
				checkboxes.forEach(cb => {
					cb.addEventListener('change', () => {
						const nowChecked = wrapper.querySelectorAll('input[type="checkbox"]:checked').length;
						badge.textContent = `${nowChecked}/${checkboxes.length}`;
						badge.className = `role-section-badge ${nowChecked > 0 ? 'role-section-badge--active' : 'role-section-badge--inactive'}`;
					});
				});
			}
		});
	}

	function toggleAllSections(container, shouldCollapse) {
		// Filament 5 collapsible sections use Alpine events
		// Events: expand-section, collapse-section with detail.id = section's collapse-id or el.id
		const sections = container.querySelectorAll('section.fi-section.fi-collapsible');

		sections.forEach(section => {
			const isCurrentlyCollapsed = section.classList.contains('fi-collapsed');

			if (shouldCollapse && !isCurrentlyCollapsed) {
				// Dispatch collapse event to Alpine
				const header = section.querySelector('.fi-section-header');
				if (header) header.click();
			} else if (!shouldCollapse && isCurrentlyCollapsed) {
				// Dispatch expand event to Alpine
				const header = section.querySelector('.fi-section-header');
				if (header) header.click();
			}
		});
	}
</script>