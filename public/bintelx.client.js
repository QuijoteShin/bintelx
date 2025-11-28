'use strict';

/**
 * BintelxClient - Reference WebSocket client for ChannelServer.
 *
 * Features:
 *  - Automatic connection + exponential backoff reconnection.
 *  - WebSocket-only authentication via {route, method, body}.
 *  - Keep-alive ping every 30s (channel.server disconnects after 65s idle default).
 *  - Subscription resume (restores channels after reconnect/auth).
 *  - System channels baked in for logout + permission updates.
 *  - Event-driven API (client.on('event', cb)).
 *
 * Usage:
 *    const client = new BintelxClient({
 *        url: 'wss://dev.local/ws/',
 *        handshakeRoute: '/api/_demo/validate'
 *    });
 *
 *    client.on('ready', async () => {
 *        await client.request('/api/_demo/login', {
 *            username: 'demo',
 *            password: 'test123'
 *        }, { method: 'POST' });
 *
 *        client.subscribe('chat.general');
 *        client.ping(); // Manual ping outside heartbeat if needed.
 *    });
 *
 *    client.on('system:logout', payload => {});
 */
class BintelxClient {
    constructor(options = {}) {
        this.url = options.url;
        if (!this.url) {
            throw new Error('BintelxClient requires a WebSocket URL.');
        }

        this.cookieName = options.cookieName || 'bnxt';
        this.tokenProvider = options.tokenProvider || (() => this.resolveBrowserToken(options.token));
        this.handshakeRoute = options.handshakeRoute || '/api/_demo/validate';
        this.handshakeMethod = options.handshakeMethod || 'POST';
        this.keepAliveIntervalMs = options.keepAliveIntervalMs || 30000;
        this.maxBackoffMs = options.maxBackoffMs || 30000;
        this.systemChannels = new Set([
            'sys.session.logout',
            'sys.permissions.update'
        ]);
        this.activeSubscriptions = new Set(options.autoSubscribe || []);
        this.eventHandlers = new Map();
        this.pendingQueue = [];
        this.correlationResolvers = new Map();

        this.backoffMs = 0;
        this.reconnectTimer = null;
        this.heartbeat = null;
        this.ws = null;
        this.state = 'disconnected';
        this.correlationPrefix = `client_${Date.now()}`;
        this.correlationCounter = 0;

        this.connect();
    }

    /* ------------------------------------------------------------------ */
    /* Public API                                                         */
    /* ------------------------------------------------------------------ */

    on(event, handler) {
        if (!this.eventHandlers.has(event)) {
            this.eventHandlers.set(event, new Set());
        }
        this.eventHandlers.get(event).add(handler);
        return () => this.eventHandlers.get(event)?.delete(handler);
    }

    isConnected() {
        return this.ws && this.ws.readyState === WebSocket.OPEN;
    }

    subscribe(channel, options = {}) {
        if (!channel) return;
        this.activeSubscriptions.add(channel);
        return this.request('/api/ws/subscribe', {
            channel,
            ...options
        });
    }

    unsubscribe(channel) {
        if (!channel) return;
        this.activeSubscriptions.delete(channel);
        return this.request('/api/ws/unsubscribe', { channel });
    }

    publish(channel, message) {
        return this.request('/api/ws/publish', { channel, message });
    }

    ping(options = {}) {
        return this.request('/api/ws/ping', {}, {
            method: 'GET',
            correlationId: options.correlationId || `ping_${Date.now()}`,
            meta: options.meta
        });
    }

    request(route, body = {}, reqOptions = {}) {
        const payload = this.buildRequestPayload(route, body, reqOptions);

        if (!this.isConnected()) {
            this.pendingQueue.push(payload);
            return Promise.resolve({ queued: true });
        }

        return this.sendPayload(payload);
    }

    disconnect(code = 1000, reason = 'client closed') {
        if (this.ws) {
            this.ws.onopen = null;
            this.ws.onmessage = null;
            this.ws.onerror = null;
            this.ws.onclose = null;
            this.ws.close(code, reason);
        }
        this.cleanupConnection();
        this.state = 'disconnected';
        this.emit('close', { code, reason, manual: true });
    }

    /* ------------------------------------------------------------------ */
    /* Connection Lifecycle                                               */
    /* ------------------------------------------------------------------ */

    connect() {
        if (this.state === 'connecting' || this.isConnected()) {
            return;
        }
        this.state = 'connecting';
        this.emit('connecting');

        this.ws = new WebSocket(this.url);
        this.ws.onopen = () => this.handleOpen();
        this.ws.onerror = (err) => this.handleError(err);
        this.ws.onclose = (event) => this.handleClose(event);
        this.ws.onmessage = (event) => this.handleMessage(event);
    }

    handleOpen() {
        this.state = 'connected';
        this.emit('open');
        this.resetBackoff();
        this.startHeartbeat();
        this.flushQueue();
        this.authenticate().catch((err) => this.emit('error', err));
    }

    handleClose(event) {
        this.emit('close', {
            code: event.code,
            reason: event.reason,
            manual: false
        });
        this.cleanupConnection();
        this.scheduleReconnect();
    }

    handleError(error) {
        this.emit('error', error);
    }

    cleanupConnection() {
        if (this.heartbeat) {
            clearInterval(this.heartbeat);
            this.heartbeat = null;
        }
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Authentication + Resume                                            */
    /* ------------------------------------------------------------------ */

    async authenticate() {
        const token = await this.resolveToken();
        if (!token) {
            this.emit('warn', new Error('No token available, skipping handshake.'));
            this.transitionToReady();
            return;
        }

        return this.request(this.handshakeRoute, { token }, {
            method: this.handshakeMethod,
            correlationId: 'handshake'
        }).then((response) => {
            if (response?.data?.success === false) {
                throw new Error(response.data.message || 'Authentication failed');
            }
            this.transitionToReady(response);
        }).catch((err) => {
            this.emit('error', err);
            this.ws?.close(4001, 'auth failed');
        });
    }

    transitionToReady(handshakeResponse) {
        this.state = 'ready';
        this.emit('ready', handshakeResponse || {});
        this.subscribeSystemChannels();
        this.resubscribeAll();
    }

    resubscribeAll() {
        this.activeSubscriptions.forEach((channel) => {
            this.request('/api/ws/subscribe', { channel });
        });
    }

    subscribeSystemChannels() {
        this.systemChannels.forEach((channel) => {
            if (!this.activeSubscriptions.has(channel)) {
                this.subscribe(channel, { persistent: true });
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Messaging Helpers                                                  */
    /* ------------------------------------------------------------------ */

    startHeartbeat() {
        if (this.heartbeat) {
            clearInterval(this.heartbeat);
        }
        this.heartbeat = setInterval(() => {
            if (this.isConnected()) {
                try {
                    this.ws.send(JSON.stringify({ type: 'ping', route:'/api/ws/ping', method:'GET', ts: Date.now() }));
                } catch (err) {
                    this.emit('error', err);
                }
            }
        }, this.keepAliveIntervalMs);
    }

    flushQueue() {
        if (!this.isConnected() || !this.pendingQueue.length) {
            return;
        }
        const queue = [...this.pendingQueue];
        this.pendingQueue = [];
        queue.forEach((payload) => this.sendPayload(payload));
    }

    sendPayload(payload) {
        return new Promise((resolve, reject) => {
            if (!this.isConnected()) {
                this.pendingQueue.push(payload);
                return resolve({ queued: true });
            }

            try {
                const json = JSON.stringify(payload);
                if (payload.correlation_id) {
                    this.correlationResolvers.set(payload.correlation_id, { resolve, reject });
                }
                this.ws.send(json);
                if (!payload.correlation_id) {
                    resolve({ sent: true });
                }
            } catch (err) {
                reject(err);
            }
        });
    }

    buildRequestPayload(route, body, reqOptions) {
        const correlation_id = reqOptions.correlationId || this.nextCorrelationId();
        const method = (reqOptions.method || 'POST').toUpperCase();
        const token = reqOptions.token || this.cachedToken || null;

        const payload = {
            route,
            method,
            body,
            correlation_id
        };

        if (reqOptions.query) {
            payload.query = reqOptions.query;
        }
        if (reqOptions.headers) {
            payload.headers = reqOptions.headers;
        }
        if (token) {
            payload.token = token;
        }
        if (reqOptions.meta) {
            payload.meta = reqOptions.meta;
        }
        return payload;
    }

    nextCorrelationId() {
        this.correlationCounter += 1;
        return `${this.correlationPrefix}_${this.correlationCounter}`;
    }

    async resolveToken() {
        try {
            const token = await this.tokenProvider();
            if (token) {
                this.cachedToken = token;
            }
            return token;
        } catch (err) {
            this.emit('error', err);
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Message Handling                                                   */
    /* ------------------------------------------------------------------ */

    handleMessage(event) {
        let payload;
        try {
            payload = JSON.parse(event.data);
        } catch (err) {
            this.emit('error', new Error('Invalid JSON payload from server'));
            return;
        }

        if (payload.correlation_id && this.correlationResolvers.has(payload.correlation_id)) {
            const resolver = this.correlationResolvers.get(payload.correlation_id);
            this.correlationResolvers.delete(payload.correlation_id);
            if (payload.status && payload.status !== 'success') {
                resolver.reject(payload);
            } else {
                resolver.resolve(payload);
            }
        }

        if (payload.type === 'system') {
            this.handleSystemMessage(payload);
        } else if (payload.type === 'error') {
            this.emit('error', payload);
        }

        this.emit('message', payload);
    }

    handleSystemMessage(payload) {
        const event = payload.event || payload.channel || 'system';
        if (event.includes('logout')) {
            this.emit('system:logout', payload);
        } else if (event.includes('permissions')) {
            this.emit('system:permissions', payload);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Reconnection logic                                                 */
    /* ------------------------------------------------------------------ */

    scheduleReconnect() {
        if (this.state === 'disconnected') {
            return;
        }
        this.state = 'disconnected';
        this.cleanupConnection();
        this.backoffMs = this.backoffMs ? Math.min(this.backoffMs * 2, this.maxBackoffMs) : 1000;
        this.emit('reconnecting', { in: this.backoffMs });

        this.reconnectTimer = setTimeout(() => {
            this.connect();
        }, this.backoffMs);
    }

    resetBackoff() {
        this.backoffMs = 0;
    }

    /* ------------------------------------------------------------------ */
    /* Event helpers                                                      */
    /* ------------------------------------------------------------------ */

    emit(event, payload) {
        if (!this.eventHandlers.has(event)) {
            if (event === 'warn') {
                console.warn('[BintelxClient]', payload);
            } else if (event === 'error') {
                console.error('[BintelxClient]', payload);
            }
            return;
        }
        this.eventHandlers.get(event).forEach((handler) => {
            try {
                handler(payload);
            } catch (err) {
                console.error('[BintelxClient] handler error', err);
            }
        });
    }

    /**
     * Default browser token resolver:
     * 1. Looks for cookie "bnxt" (or name passed via options.cookieName).
     * 2. Falls back to localStorage key (same name).
     * 3. Finally uses the static token provided in options.
     */
    resolveBrowserToken(fallbackToken = null) {
        if (typeof document !== 'undefined') {
            const key = this.cookieName || 'bnxt';
            const tokenFromCookie = this.readCookie(key);
            if (tokenFromCookie) {
                return tokenFromCookie;
            }
            if (typeof localStorage !== 'undefined') {
                const lsToken = localStorage.getItem(key);
                if (lsToken) {
                    return lsToken;
                }
            }
        }
        return fallbackToken;
    }

    readCookie(name) {
        if (typeof document === 'undefined') return null;
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    }
}

// Export for browsers and bundlers.
if (typeof window !== 'undefined') {
    window.BintelxClient = BintelxClient;
}
if (typeof module !== 'undefined' && module.exports) {
    module.exports = BintelxClient;
}
