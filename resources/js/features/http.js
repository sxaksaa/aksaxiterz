function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function setCsrfToken(token) {
    if (!token) return '';

    let meta = document.querySelector('meta[name="csrf-token"]');

    if (!meta) {
        meta = document.createElement('meta');
        meta.setAttribute('name', 'csrf-token');
        document.head.appendChild(meta);
    }

    meta.setAttribute('content', token);
    document.querySelectorAll('input[name="_token"]').forEach((input) => {
        input.value = token;
    });

    return token;
}

async function refreshCsrfToken() {
    const response = await fetch('/csrf-token', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error('Your secure session expired. Refresh the page and try again.');
    }

    const data = await response.json().catch(() => ({}));
    const token = data.token || '';

    if (!token) {
        throw new Error('Your secure session expired. Refresh the page and try again.');
    }

    return setCsrfToken(token);
}

export function registerHttp() {
    window.aksaCsrfToken = csrfToken;

    window.setAksaCsrfToken = setCsrfToken;

    window.refreshAksaCsrfToken = refreshCsrfToken;

    window.aksaFetchWithCsrf = async function(url, options = {}) {
        const requestOptions = {
            ...options,
            credentials: options.credentials || 'same-origin',
        };
        const requestHeaders = new Headers(requestOptions.headers || {});

        requestHeaders.set('X-CSRF-TOKEN', csrfToken());
        requestOptions.headers = requestHeaders;

        let response = await fetch(url, requestOptions);

        if (response.status !== 419) {
            return response;
        }

        await refreshCsrfToken();
        const retryHeaders = new Headers(options.headers || {});

        retryHeaders.set('X-CSRF-TOKEN', csrfToken());

        return fetch(url, {
            ...options,
            credentials: options.credentials || 'same-origin',
            headers: retryHeaders,
        });
    };
}
