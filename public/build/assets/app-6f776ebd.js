document.querySelector('[data-menu-toggle]')?.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
document.querySelector('[data-password-toggle]')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const input = document.querySelector('#password');
    if (!(button instanceof HTMLButtonElement) || !(input instanceof HTMLInputElement)) return;
    const visible = input.type === 'text';
    input.type = visible ? 'password' : 'text';
    button.textContent = visible ? '◉' : '◌';
    button.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
});

document.querySelector('[data-search-collapse]')?.addEventListener('click', (event) => {
    const button = event.currentTarget;
    const card = document.querySelector('[data-search-card]');
    const body = document.querySelector('[data-search-body]');
    if (!(button instanceof HTMLButtonElement) || !(card instanceof HTMLElement) || !(body instanceof HTMLElement)) return;
    const collapsed = card.classList.toggle('is-collapsed');
    body.hidden = collapsed;
    button.setAttribute('aria-expanded', String(!collapsed));
    button.textContent = collapsed ? '⌄' : '⌃';
});

const form = document.querySelector('[data-query-form]');
const result = document.querySelector('[data-query-result]');
const resultCard = document.querySelector('[data-query-result-card]');
const resultLoading = document.querySelector('[data-query-loading]');
const resultLoadingText = document.querySelector('[data-query-loading-text]');
const feedback = document.querySelector('[data-query-feedback]');
const submit = document.querySelector('[data-query-submit]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

const pageState = {
    currentPage: 1,
    knownTotal: null,
    pageSize: 20,
    rowCount: 0,
    hasMore: false,
    nextCursor: '',
    cursors: { 1: '' },
};

const formatNumber = (value) => new Intl.NumberFormat('es-PE').format(Number(value) || 0);

const showFeedback = (messages) => {
    if (!(feedback instanceof HTMLElement)) return;
    feedback.replaceChildren();
    const list = document.createElement('ul');
    messages.forEach((message) => {
        const item = document.createElement('li');
        item.textContent = message;
        list.append(item);
    });
    feedback.append(list);
    feedback.hidden = false;
};

const clearFeedback = () => {
    if (feedback instanceof HTMLElement) {
        feedback.hidden = true;
        feedback.replaceChildren();
    }
};

const setResultLoading = (loading, text = 'Cargando resultados...') => {
    if (!(resultCard instanceof HTMLElement) || !(resultLoading instanceof HTMLElement)) return;
    resultCard.classList.toggle('is-loading', loading);
    resultLoading.hidden = !loading;
    if (resultLoadingText instanceof HTMLElement) {
        resultLoadingText.textContent = text;
    }
};

const postForm = async (url, data) => {
    const response = await fetch(url, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf,
        },
    });

    const payload = await response.json();
    if (!response.ok && typeof payload.html !== 'string') {
        throw new Error(
            payload.message ||
            Object.values(payload.errors ?? {}).flat()[0] ||
            'No se pudo completar la operación.'
        );
    }

    payload._request_ok = response.ok;
    return payload;
};

const resetPaginationState = () => {
    pageState.currentPage = 1;
    pageState.knownTotal = null;
    pageState.pageSize = 20;
    pageState.rowCount = 0;
    pageState.hasMore = false;
    pageState.nextCursor = '';
    pageState.cursors = { 1: '' };
};

const syncPaginationStateFromDom = () => {
    if (!(result instanceof HTMLElement)) return;
    const pagination = result.querySelector('[data-server-pagination]');
    if (!(pagination instanceof HTMLElement)) {
        pageState.rowCount = 0;
        pageState.hasMore = false;
        pageState.nextCursor = '';
        return;
    }

    pageState.pageSize = Number(pagination.dataset.pageSize || 20);
    pageState.rowCount = Number(pagination.dataset.rowCount || 0);

    if (pageState.knownTotal === null) {
        pageState.knownTotal = Number(pagination.dataset.totalRows || 0);
    }

    pageState.hasMore = pagination.dataset.hasMore === '1';
    pageState.nextCursor = pagination.dataset.nextCursor || '';

    if (pageState.hasMore && pageState.nextCursor) {
        pageState.cursors[pageState.currentPage + 1] = pageState.nextCursor;
    }
};

const getTotalPages = () => {
    const total = Number(pageState.knownTotal || 0);
    return Math.max(1, Math.ceil(total / Math.max(1, pageState.pageSize)));
};

const canNavigateToPage = (page) => {
    if (page < 1) return false;
    if (page === pageState.currentPage) return true;
    if (page in pageState.cursors) return true;
    return page === pageState.currentPage + 1 && pageState.hasMore && pageState.nextCursor !== '';
};

const getVisiblePages = (currentPage, totalPages) => {
    if (totalPages <= 7) {
        return Array.from({ length: totalPages }, (_, index) => index + 1);
    }

    if (currentPage <= 4) {
        return [1, 2, 3, 4, 5, 'ellipsis', totalPages];
    }

    if (currentPage >= totalPages - 3) {
        return [1, 'ellipsis', totalPages - 4, totalPages - 3, totalPages - 2, totalPages - 1, totalPages];
    }

    return [1, 'ellipsis', currentPage - 1, currentPage, currentPage + 1, 'ellipsis', totalPages];
};

const renderPaginationControls = () => {
    if (!(result instanceof HTMLElement)) return;

    const pagination = result.querySelector('[data-server-pagination]');
    if (!(pagination instanceof HTMLElement)) return;

    const summary = pagination.querySelector('[data-pagination-summary]');
    const previous = pagination.querySelector('[data-query-prev]');
    const next = pagination.querySelector('[data-query-next]');
    const pagesContainer = pagination.querySelector('[data-query-pages]');

    const totalRows = Number(pageState.knownTotal || 0);
    const totalPages = getTotalPages();
    const fromRow = pageState.rowCount > 0 ? ((pageState.currentPage - 1) * pageState.pageSize) + 1 : 0;
    const toRow = pageState.rowCount > 0 ? fromRow + pageState.rowCount - 1 : 0;

    if (summary instanceof HTMLElement) {
        summary.textContent = `Mostrando registros ${formatNumber(fromRow)} a ${formatNumber(toRow)} de un total de ${formatNumber(totalRows)} registros`;
    }

    if (previous instanceof HTMLButtonElement) {
        previous.disabled = pageState.currentPage <= 1;
    }

    if (next instanceof HTMLButtonElement) {
        next.disabled = !pageState.hasMore;
        next.dataset.cursor = pageState.nextCursor;
    }

    if (!(pagesContainer instanceof HTMLElement)) return;

    pagesContainer.replaceChildren();
    getVisiblePages(pageState.currentPage, totalPages).forEach((page) => {
        if (page === 'ellipsis') {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'server-page-ellipsis';
            ellipsis.textContent = '…';
            pagesContainer.append(ellipsis);
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'server-page-number';
        button.textContent = String(page);
        button.dataset.page = String(page);

        if (page === pageState.currentPage) {
            button.classList.add('is-active');
            button.setAttribute('aria-current', 'page');
        } else if (!canNavigateToPage(page)) {
            button.disabled = true;
            button.classList.add('is-unavailable');
        }

        pagesContainer.append(button);
    });
};

const requestQueryPage = async (cursor = '') => {
    if (!(form instanceof HTMLFormElement) || !(result instanceof HTMLElement)) return null;

    const data = new FormData(form);
    if (cursor) {
        data.set('cursor', cursor);
    }
    if (pageState.knownTotal !== null) {
        data.set('known_total', String(pageState.knownTotal));
    }

    const payload = await postForm(form.action, data);

    if (typeof payload.html === 'string') {
        result.innerHTML = payload.html;
    }

    if (Number.isFinite(Number(payload.total_rows))) {
        pageState.knownTotal = Number(payload.total_rows);
    }

    syncPaginationStateFromDom();
    renderPaginationControls();

    if (payload._request_ok === false) {
        throw new Error(payload.message || 'No se pudo ejecutar la consulta.');
    }

    return payload;
};

const goToPage = async (targetPage) => {
    if (targetPage === pageState.currentPage) return;

    const cursor = targetPage === 1
        ? ''
        : (pageState.cursors[targetPage] ?? '');

    if (targetPage !== 1 && !cursor) return;

    const previousPage = pageState.currentPage;
    setResultLoading(true, `Cargando página ${targetPage}...`);
    clearFeedback();

    try {
        await requestQueryPage(cursor);
        pageState.currentPage = targetPage;
        syncPaginationStateFromDom();
        renderPaginationControls();
    } catch (error) {
        pageState.currentPage = previousPage;
        renderPaginationControls();
        showFeedback([error instanceof Error ? error.message : 'No se pudo cargar la página solicitada.']);
    } finally {
        setResultLoading(false);
    }
};

document.querySelector('[data-clear-form]')?.addEventListener('click', () => {
    if (!(form instanceof HTMLFormElement)) return;
    form.reset();
    form.querySelectorAll('input[type="checkbox"]').forEach((control) => {
        if (control instanceof HTMLInputElement) control.checked = true;
    });
    resetPaginationState();
});

if (form instanceof HTMLFormElement && result instanceof HTMLElement) {
    form.addEventListener('input', () => {
        const hadPaginationState = pageState.knownTotal !== null || pageState.currentPage > 1 || pageState.nextCursor !== '';
        if (!hadPaginationState) return;

        resetPaginationState();
        result.querySelectorAll('[data-query-next], [data-query-prev], [data-query-pages] .server-page-number').forEach((button) => {
            if (button instanceof HTMLButtonElement) button.disabled = true;
        });
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        clearFeedback();
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = true;
            submit.textContent = 'Consultando...';
        }

        const previousState = structuredClone(pageState);
        resetPaginationState();
        setResultLoading(true, 'Consultando...');

        try {
            await requestQueryPage('');
        } catch (error) {
            Object.assign(pageState, previousState);
            showFeedback([error instanceof Error ? error.message : 'No se pudo ejecutar la consulta.']);
        } finally {
            setResultLoading(false);
            if (submit instanceof HTMLButtonElement) {
                submit.disabled = false;
                submit.textContent = '⌕ Ejecutar consulta';
            }
        }
    });

    document.addEventListener('click', async (event) => {
        const target = event.target instanceof Element
            ? event.target.closest('[data-export-button], [data-template-save], [data-query-next], [data-query-prev], .server-page-number[data-page]')
            : null;
        if (!(target instanceof HTMLButtonElement)) return;

        if (target.matches('.server-page-number[data-page]')) {
            const targetPage = Number(target.dataset.page || 1);
            if (Number.isFinite(targetPage)) {
                await goToPage(targetPage);
            }
            return;
        }

        if (target.hasAttribute('data-query-next')) {
            await goToPage(pageState.currentPage + 1);
            return;
        }

        if (target.hasAttribute('data-query-prev')) {
            await goToPage(pageState.currentPage - 1);
            return;
        }

        if (target.hasAttribute('data-template-save')) {
            const name = window.prompt('Nombre de la plantilla:');
            if (!name) return;
            const data = new FormData(form);
            data.append('template_name', name);
            try {
                const payload = await postForm(form.dataset.templateUrl ?? '', data);
                window.alert(payload.message);
            } catch (error) {
                showFeedback([error instanceof Error ? error.message : 'No se pudo guardar la plantilla.']);
            }
            return;
        }

        target.disabled = true;
        const original = target.textContent;
        target.textContent = 'Enviando...';
        try {
            const payload = await postForm(form.dataset.exportUrl ?? '', new FormData(form));
            window.alert(payload.message);
        } catch (error) {
            showFeedback([error instanceof Error ? error.message : 'No se pudo enviar la exportación.']);
        } finally {
            target.disabled = false;
            target.textContent = original;
        }
    });
}

syncPaginationStateFromDom();
renderPaginationControls();

if (document.querySelector('[data-refresh-tasks]')) {
    window.setTimeout(() => window.location.reload(), 15000);
}
