const menu = document.querySelector('[data-principal-menu]');
const trigger = document.querySelector('[data-principal-menu-trigger]');

if (menu && trigger) {
    trigger.addEventListener('click', () => menu.showModal());
    menu.addEventListener('close', () => trigger.focus());
    menu.addEventListener('click', (event) => {
        if (event.target === menu) {
            menu.close();
        }
    });
}

// Desktop rail toggle: collapses the Principal sidebar to the ~72px icon rail
// documented in docs/DESIGN_SYSTEM.md and remembers the choice between pages.
// The labels stay in the DOM (visually hidden by CSS) and every link keeps its
// `title`, so the rail stays understandable and keyboard-focusable.
const shell = document.querySelector('[data-principal-shell]');
const sidebarToggle = document.querySelector('[data-principal-sidebar-toggle]');

if (shell && sidebarToggle) {
    const storageKey = 'lba.principal.sidebar.collapsed';

    const applyCollapsed = (collapsed) => {
        shell.toggleAttribute('data-principal-collapsed', collapsed);
        sidebarToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');

        const label = collapsed ? sidebarToggle.dataset.labelExpand : sidebarToggle.dataset.labelCollapse;

        if (label) {
            sidebarToggle.setAttribute('aria-label', label);
            sidebarToggle.setAttribute('title', label);
        }
    };

    let collapsed = false;

    try {
        collapsed = window.localStorage.getItem(storageKey) === 'true';
    } catch (error) {
        // Storage is unavailable (private mode): fall back to the expanded rail.
    }

    applyCollapsed(collapsed);

    sidebarToggle.addEventListener('click', () => {
        collapsed = !shell.hasAttribute('data-principal-collapsed');
        applyCollapsed(collapsed);

        try {
            window.localStorage.setItem(storageKey, String(collapsed));
        } catch (error) {
            // Persisting is best-effort only.
        }
    });
}

// Top-right account menu: "Profil Akun", "Ubah Kata Sandi" and "Keluar".
const account = document.querySelector('[data-principal-account]');

if (account) {
    const accountTrigger = account.querySelector('[data-principal-account-trigger]');
    const accountMenu = account.querySelector('[data-principal-account-menu]');

    if (accountTrigger && accountMenu) {
        const closeAccountMenu = () => {
            accountMenu.setAttribute('hidden', '');
            accountTrigger.setAttribute('aria-expanded', 'false');
        };

        accountTrigger.addEventListener('click', (event) => {
            event.stopPropagation();
            if (accountMenu.hasAttribute('hidden')) {
                accountMenu.removeAttribute('hidden');
                accountTrigger.setAttribute('aria-expanded', 'true');
            } else {
                closeAccountMenu();
            }
        });

        document.addEventListener('click', (event) => {
            if (!account.contains(event.target)) {
                closeAccountMenu();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAccountMenu();
            }
        });
    }
}

// Tenant database detail: copy the SERVER-derived physical database name to the
// clipboard. The name is rendered by the server (computed from the tenant id);
// this only copies the text already on screen.
const copyDatabase = document.querySelector('[data-copy-database]');
const databaseName = document.querySelector('[data-database-name]');

if (copyDatabase && databaseName) {
    copyDatabase.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(databaseName.textContent.trim());
            if (copyDatabase.dataset.copiedLabel) {
                copyDatabase.textContent = copyDatabase.dataset.copiedLabel;
            }
        } catch (error) {
            // Clipboard is unavailable (e.g. an insecure context): the name
            // stays visible so the operator can copy it by hand.
        }
    });
}

// "Tambah/Ubah Mitra" database mode: reveal the MANUAL database fields only
// when Mode = Manual, so a "server" mode can never submit a half-filled target.
const databaseMode = document.querySelector('[data-database-mode]');
const databaseManual = document.querySelector('[data-database-manual]');

if (databaseMode && databaseManual) {
    const applyDatabaseMode = () => {
        databaseManual.hidden = databaseMode.value !== 'manual';
    };

    applyDatabaseMode();
    databaseMode.addEventListener('change', applyDatabaseMode);
}

// Destructive-action confirmations ("Hapus Brand" / "Hapus Mitra"). Each
// trigger opens the <dialog> named in `data-confirm-action`, which renders the
// exact item name and the impact; only the form INSIDE the dialog performs the
// DELETE. Closing the dialog (Cancel, backdrop, Escape) leaves the record
// untouched.
document.querySelectorAll('[data-confirm-action]').forEach((trigger) => {
    const dialog = document.getElementById(trigger.dataset.confirmAction);

    if (!(dialog instanceof HTMLDialogElement)) {
        return;
    }

    trigger.addEventListener('click', () => dialog.showModal());

    dialog.addEventListener('close', () => trigger.focus());

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    dialog.querySelector('[data-confirm-cancel]')?.addEventListener('click', () => dialog.close());
});

// LBA case detail — optional live microscope capture. The captured frame is
// POSTed to the SAME authenticated, CSRF-protected private intake endpoint as a
// manual import; nothing is stored client-side and the server re-checks every
// gate (editability, consent, tenant and branch scope).
const capture = document.querySelector('[data-lba-capture]');

if (capture) {
    const video = capture.querySelector('[data-lba-capture-video]');
    const startButton = capture.querySelector('[data-lba-capture-start]');
    const stillButton = capture.querySelector('[data-lba-capture-still]');
    const stopButton = capture.querySelector('[data-lba-capture-stop]');
    const status = capture.querySelector('[data-lba-capture-status]');
    const tokenInput = capture.querySelector('[data-lba-capture-token]');
    const deviceSelect = capture.querySelector('[data-lba-capture-device]');
    const captureUrl = capture.dataset.captureUrl;
    const errorMessage = capture.dataset.error;

    let stream = null;

    const setStatus = (message) => {
        if (status) {
            status.textContent = message;
        }
    };

    const enumerateDevices = async () => {
        if (!navigator.mediaDevices?.enumerateDevices || !deviceSelect) {
            if (deviceSelect) {
                deviceSelect.disabled = true;
            }
            return;
        }

        try {
            const selectedDeviceId = deviceSelect.value;
            const devices = (await navigator.mediaDevices.enumerateDevices())
                .filter((device) => device.kind === 'videoinput');
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = capture.dataset.defaultDeviceLabel;
            deviceSelect.replaceChildren(defaultOption);

            devices.forEach((device, index) => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.textContent = device.label || `${capture.dataset.deviceFallback} ${index + 1}`;
                deviceSelect.append(option);
            });

            deviceSelect.disabled = devices.length === 0;
            if (devices.some((device) => device.deviceId === selectedDeviceId)) {
                deviceSelect.value = selectedDeviceId;
            } else {
                deviceSelect.value = '';
            }
            if (devices.length === 0) {
                setStatus(capture.dataset.deviceNone);
            }
        } catch (error) {
            deviceSelect.disabled = true;
        }
    };

    const stop = () => {
        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }
        if (video) {
            video.srcObject = null;
        }
        startButton?.removeAttribute('hidden');
        stillButton?.setAttribute('hidden', '');
        stopButton?.setAttribute('hidden', '');
    };

    const start = async () => {
        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus(errorMessage);
            return;
        }

        try {
            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
            }
            const selectedDeviceId = deviceSelect?.value;
            stream = await navigator.mediaDevices.getUserMedia({
                video: selectedDeviceId ? { deviceId: { exact: selectedDeviceId } } : true,
                audio: false,
            });
            video.srcObject = stream;
            await video.play();
            startButton?.setAttribute('hidden', '');
            stillButton?.removeAttribute('hidden');
            stopButton?.removeAttribute('hidden');
            setStatus('');
            await enumerateDevices();
        } catch (error) {
            stop();
            setStatus(errorMessage);
        }
    };

    const upload = async (blob) => {
        const form = new FormData();
        form.append('image', blob, `capture-${Date.now()}.jpg`);
        form.append('instrument_label', stream?.getVideoTracks()[0]?.label || '');
        if (tokenInput) {
            form.append('_token', tokenInput.value);
        }
        const response = await fetch(captureUrl, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { Accept: 'text/html' },
        });
        if (!response.ok) {
            throw new Error('upload failed');
        }
        window.location.reload();
    };

    enumerateDevices();

    startButton?.addEventListener('click', async () => {
        await start();
    });

    deviceSelect?.addEventListener('change', async () => {
        if (stream) {
            await start();
        }
    });

    stillButton?.addEventListener('click', () => {
        if (!stream || !video) {
            return;
        }
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 1280;
        canvas.height = video.videoHeight || 720;
        const context = canvas.getContext('2d');
        if (!context) {
            setStatus(errorMessage);
            return;
        }
        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(async (blob) => {
            if (!blob) {
                return;
            }
            try {
                await upload(blob);
            } catch (error) {
                setStatus(errorMessage);
            }
        }, 'image/jpeg', 0.92);
    });

    stopButton?.addEventListener('click', stop);
    window.addEventListener('pagehide', stop, { once: true });
    navigator.mediaDevices?.addEventListener?.('devicechange', enumerateDevices);
}

// Menu Dinamis — nested drag-and-drop for the per-group navigation builder.
//
// Dragging a row's handle onto ANOTHER row nests it as that row's child;
// dropping it on the thin gap between rows makes it a sibling at that position.
// The visible arrangement is serialized to `{id, parent_id, sort_order}` and
// POSTed to the reorder endpoint, which re-checks every id against the panel
// before writing. Nothing here is an authorization decision — the server owns
// that; this only captures the position the Principal dragged.
const menuBuilder = document.querySelector('[data-menu-builder]');

if (menuBuilder) {
    const reorderUrl = menuBuilder.dataset.menuReorder;
    const token = menuBuilder.dataset.menuToken;
    const errorMessage = menuBuilder.dataset.menuError;
    const saveButton = menuBuilder.querySelector('[data-menu-save]');

    let dragged = null;

    const clearHighlight = () => {
        menuBuilder.querySelectorAll('[data-menu-row].ring-2').forEach((row) => {
            row.classList.remove('ring-2', 'ring-brand-400');
        });
    };

    const childrenList = (item) => {
        let list = item.querySelector(':scope > ul.menu-tree');

        if (!list) {
            list = document.createElement('ul');
            list.className = 'menu-tree mt-2 ms-6 grid gap-2 border-s border-slate-200 ps-3';
            list.setAttribute('role', 'list');
            const gap = document.createElement('li');
            gap.className = 'menu-tree__gap h-2 rounded bg-slate-100';
            gap.setAttribute('data-menu-drop', '');
            gap.setAttribute('aria-hidden', 'true');
            list.append(gap);
            item.append(list);
        }

        return list;
    };

    const canDrop = (target) => dragged && target && target !== dragged && !dragged.contains(target);

    // The handle is the only draggable element so the row's link and buttons
    // stay clickable; the drag moves the whole `li`.
    menuBuilder.querySelectorAll('[data-menu-handle]').forEach((handle) => {
        handle.setAttribute('draggable', 'true');

        handle.addEventListener('dragstart', (event) => {
            dragged = handle.closest('[data-menu-id]');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', dragged?.dataset.menuId ?? '');
            dragged?.classList.add('opacity-50');
        });

        handle.addEventListener('dragend', () => {
            dragged?.classList.remove('opacity-50');
            dragged = null;
            clearHighlight();
        });
    });

    // Drop onto a row → becomes that row's child (appended to its child list).
    menuBuilder.querySelectorAll('[data-menu-row]').forEach((row) => {
        row.addEventListener('dragover', (event) => {
            if (!canDrop(row.closest('[data-menu-id]'))) {
                return;
            }
            event.preventDefault();
            row.classList.add('ring-2', 'ring-brand-400');
        });

        row.addEventListener('dragleave', () => row.classList.remove('ring-2', 'ring-brand-400'));

        row.addEventListener('drop', (event) => {
            const item = row.closest('[data-menu-id]');

            if (!canDrop(item)) {
                return;
            }

            event.preventDefault();
            childrenList(item).append(dragged);
            clearHighlight();
        });
    });

    // Drop onto a gap → becomes a sibling inserted at that position.
    menuBuilder.querySelectorAll('[data-menu-drop]').forEach((gap) => {
        gap.addEventListener('dragover', (event) => {
            if (!canDrop(gap)) {
                return;
            }
            event.preventDefault();
            gap.classList.add('bg-brand-200');
        });

        gap.addEventListener('dragleave', () => gap.classList.remove('bg-brand-200'));

        gap.addEventListener('drop', (event) => {
            if (!canDrop(gap)) {
                return;
            }

            event.preventDefault();
            gap.parentNode.insertBefore(dragged, gap);
            clearHighlight();
        });
    });

    const serialize = () => {
        const nodes = [];

        const walk = (list, parentId) => {
            let order = 0;

            list.querySelectorAll(':scope > [data-menu-id]').forEach((item) => {
                const id = item.dataset.menuId;
                nodes.push({ id, parent_id: parentId, sort_order: order });
                order += 1;

                const childList = item.querySelector(':scope > ul.menu-tree');
                if (childList) {
                    walk(childList, id);
                }
            });
        };

        menuBuilder.querySelectorAll('[data-menu-root]').forEach((root) => walk(root, null));

        return nodes;
    };

    saveButton?.addEventListener('click', async () => {
        try {
            const response = await fetch(reorderUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    panel: menuBuilder.dataset.menuPanel,
                    tree: serialize(),
                }),
            });

            if (!response.ok) {
                throw new Error('reorder failed');
            }

            window.location.reload();
        } catch (error) {
            window.alert(errorMessage);
        }
    });
}
