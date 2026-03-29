<style>
	/*
    Custom Admin Styles
    Add your custom CSS for the admin panel here.
*/

	/* ─── Sub-navigation: base + collapse animation ─── */
	.fi-sidebar-sub-group-items {
		padding-inline-start: 1.25rem;
		overflow: hidden;
		max-height: 500px;
		transition: max-height 0.25s ease, opacity 0.2s ease;
		opacity: 1;
	}

	.fi-sidebar-sub-group-items.is-collapsed {
		max-height: 0;
		opacity: 0;
		pointer-events: none;
	}

	/* ─── Sub-navigation: hide grouped border ─── */
	.fi-sidebar-sub-group-items .fi-sidebar-item-grouped-border {
		display: none;
	}

	/* ─── Sub-navigation: smaller font for child items ─── */
	.fi-sidebar-sub-group-items .fi-sidebar-item-label {
		font-size: 0.8125rem; /* 13px ≈ 1px smaller than default 14px */
	}

	/* ─── Toggle chevron icon ─── */
	.fi-sidebar-item-has-toggle .fi-sidebar-toggle-icon {
		width: 1.125rem;
		height: 1.125rem;
		transition: transform 0.25s ease;
		opacity: 0.4;
		flex-shrink: 0;
		margin-inline-start: auto;
		cursor: pointer;
		padding: 0.25rem;
		box-sizing: content-box;
	}

	.fi-sidebar-item-has-toggle:hover .fi-sidebar-toggle-icon {
		opacity: 0.7;
	}

	.fi-sidebar-item-has-toggle .fi-sidebar-toggle-icon.is-collapsed {
		transform: rotate(-90deg);
	}
</style>

<script>
	document.addEventListener('DOMContentLoaded', function () {
		initSidebarToggle();
		// Re-init after Livewire navigation (SPA mode)
		document.addEventListener('livewire:navigated', initSidebarToggle);
	});

	function initSidebarToggle() {
		const STORAGE_PREFIX = 'fi-sidebar-toggle:';
		const subGroups = document.querySelectorAll('.fi-sidebar-sub-group-items');

		subGroups.forEach(function (subGroup) {
			const parentItem = subGroup.closest('.fi-sidebar-item');
			if (!parentItem) return;

			// Avoid double-init
			if (parentItem.classList.contains('fi-sidebar-item-has-toggle')) return;

			parentItem.classList.add('fi-sidebar-item-has-toggle');

			const btn = parentItem.querySelector(':scope > .fi-sidebar-item-btn');
			if (!btn) return;

			// Build unique storage key from parent label text
			const labelEl = btn.querySelector(':scope > .fi-sidebar-item-label');
			const labelText = labelEl ? labelEl.textContent.trim() : 'default';
			const storageKey = STORAGE_PREFIX + labelText.replace(/\s+/g, '_').toLowerCase();

			// Add chevron icon
			const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
			chevron.setAttribute('viewBox', '0 0 20 20');
			chevron.setAttribute('fill', 'currentColor');
			chevron.classList.add('fi-sidebar-toggle-icon');
			chevron.innerHTML = '<path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>';

			btn.appendChild(chevron);

			// Check if parent menu is currently active
			const isParentActive = parentItem.classList.contains('fi-active')
				|| parentItem.classList.contains('fi-sidebar-item-has-active-child-items')
				|| subGroup.querySelector('.fi-active') !== null;

			// Determine initial state:
			// - NOT active → always collapsed, clear stored state
			// - Active → respect localStorage, default collapsed if no stored state
			let shouldCollapse;
			if (!isParentActive) {
				shouldCollapse = true;
				localStorage.removeItem(storageKey);
			} else {
				shouldCollapse = localStorage.getItem(storageKey) !== '0'; // default collapsed
			}

			if (shouldCollapse) {
				subGroup.classList.add('is-collapsed');
				chevron.classList.add('is-collapsed');
			}

			// Toggle on chevron icon click only — parent link stays navigable
			chevron.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();

				subGroup.classList.toggle('is-collapsed');
				chevron.classList.toggle('is-collapsed');
				localStorage.setItem(storageKey, subGroup.classList.contains('is-collapsed') ? '1' : '0');
			});
		});
	}
</script>