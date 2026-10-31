(function () {
    "use strict";

    function initEquipmentInventory() {
        var panel = document.querySelector('[data-admin-dashboard-panel="equipments"]');
        if (!panel) return;

        var search = panel.querySelector("[data-equipment-search]");
        var toggleAll = panel.querySelector("[data-equipment-toggle-all]");
        var results = panel.querySelector("[data-equipment-results]");
        var emptySearch = panel.querySelector("[data-equipment-empty-search]");
        var storageKey = "creaty-equipment-expanded:" + window.location.pathname;
        var expandedKeys = [];

        try {
            var storedKeys = JSON.parse(window.sessionStorage.getItem(storageKey) || "[]");
            if (Array.isArray(storedKeys)) expandedKeys = storedKeys;
        } catch (error) {
            // Inventory remains usable when browser storage is unavailable.
        }

        var groups = Array.from(panel.querySelectorAll("[data-equipment-group]")).map(function (row) {
            var button = row.querySelector("[data-equipment-expand]");
            return {
                row: row,
                button: button,
                detail: document.getElementById(button.getAttribute("aria-controls")),
                key: row.getAttribute("data-product-key"),
                searchText: (row.getAttribute("data-search") || "").toLocaleLowerCase(),
                quantity: Number(row.getAttribute("data-quantity")) || 0
            };
        });

        function setExpanded(group, expanded) {
            group.button.setAttribute("aria-expanded", String(expanded));
            group.detail.hidden = !expanded || group.row.hidden;
            group.row.classList.toggle("is-expanded", expanded);
            group.button.querySelector("[data-equipment-expand-label]").textContent = expanded ? "Hide serial numbers" : "View serial numbers";
        }

        function rememberExpansion(group, expanded) {
            expandedKeys = expandedKeys.filter(function (key) { return key !== group.key; });
            if (expanded) expandedKeys.push(group.key);
            try {
                window.sessionStorage.setItem(storageKey, JSON.stringify(expandedKeys));
            } catch (error) {
                // Persistence is optional; expansion still works for this page.
            }
        }

        function updateToggleAll() {
            var visible = groups.filter(function (group) { return !group.row.hidden; });
            var allExpanded = visible.length > 0 && visible.every(function (group) {
                return group.button.getAttribute("aria-expanded") === "true";
            });
            toggleAll.disabled = visible.length === 0;
            toggleAll.textContent = allExpanded ? "Collapse all models" : "Expand all models";
            toggleAll.setAttribute("data-expanded", String(allExpanded));
        }

        function filterGroups() {
            var query = search.value.trim().toLocaleLowerCase();
            var words = query.split(/\s+/).filter(Boolean);
            var visibleModels = 0;
            var visibleUnits = 0;

            groups.forEach(function (group) {
                var matches = words.every(function (word) { return group.searchText.indexOf(word) !== -1; });
                group.row.hidden = !matches;
                setExpanded(group, matches && (query !== "" || expandedKeys.indexOf(group.key) !== -1));
                if (matches) {
                    visibleModels++;
                    visibleUnits += group.quantity;
                }
            });

            results.textContent = visibleModels + (visibleModels === 1 ? " model" : " models") + " · " + visibleUnits + (visibleUnits === 1 ? " unit" : " units");
            emptySearch.hidden = query === "" || visibleModels > 0;
            updateToggleAll();
        }

        groups.forEach(function (group) {
            function toggleGroup() {
                var expanded = group.button.getAttribute("aria-expanded") !== "true";
                setExpanded(group, expanded);
                rememberExpansion(group, expanded);
                updateToggleAll();
            }

            group.button.addEventListener("click", toggleGroup);
            group.row.addEventListener("click", function (event) {
                if (!event.target.closest("button, a, input, select, form")) toggleGroup();
            });
        });

        search.addEventListener("input", filterGroups);
        toggleAll.addEventListener("click", function () {
            var expanded = toggleAll.getAttribute("data-expanded") !== "true";
            groups.forEach(function (group) {
                if (group.row.hidden) return;
                setExpanded(group, expanded);
                rememberExpansion(group, expanded);
            });
            updateToggleAll();
        });

        filterGroups();
    }

    // Maintenance / Broken need a reason: show the picker instead of submitting immediately.
    var REASON_STATUSES = ["maintenance", "broken"];

    function showReasonSelect(container, status) {
        var active = null;
        container.querySelectorAll("select[data-reason-for]").forEach(function (select) {
            var match = select.getAttribute("data-reason-for") === status;
            select.hidden = !match;
            select.disabled = !match;
            select.required = match;
            if (!match) select.value = "";
            if (match) active = select;
        });
        return active;
    }

    function bindOtherNote(select, note) {
        if (!select || !note) return;
        function sync() {
            note.required = select.value === "Other";
        }
        select.addEventListener("change", sync);
        sync();
    }

    function initStatusReasonForms() {
        document.querySelectorAll("[data-equipment-status-form]").forEach(function (form) {
            var statusSelect = form.querySelector("select[name='status']");
            var panel = form.querySelector("[data-equipment-reason-panel]");
            if (!statusSelect || !panel) return;

            var note = panel.querySelector("[data-reason-note]");
            var current = statusSelect.getAttribute("data-current-status") || statusSelect.value;

            function closePanel() {
                panel.hidden = true;
                showReasonSelect(panel, "");
                if (note) { note.value = ""; note.required = false; }
                statusSelect.value = current;
            }

            statusSelect.addEventListener("change", function () {
                var next = statusSelect.value;
                if (REASON_STATUSES.indexOf(next) === -1) {
                    form.submit();
                    return;
                }
                var active = showReasonSelect(panel, next);
                panel.hidden = false;
                bindOtherNote(active, note);
                if (active) active.focus();
            });

            var cancel = panel.querySelector("[data-equipment-reason-cancel]");
            if (cancel) cancel.addEventListener("click", closePanel);
        });

        document.querySelectorAll("[data-equipment-inspect-form]").forEach(function (form) {
            var outcome = form.querySelector("select[name='outcome']");
            var note = form.querySelector("input[name='incident_note']");
            if (!outcome) return;

            outcome.addEventListener("change", function () {
                var active = showReasonSelect(form, outcome.value);
                if (note) note.required = outcome.value === "broken";
                bindOtherNote(active, note && outcome.value !== "broken" ? note : null);
            });
        });
    }

    function initAll() {
        initEquipmentInventory();
        initStatusReasonForms();
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", initAll);
    } else {
        initAll();
    }
}());
