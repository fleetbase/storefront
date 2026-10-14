/**
 * Socket channels read for as long as a component wants them.
 *
 * Channels are shared with other listeners (Fleet-Ops reads `company.{uuid}` too), so each one is
 * read through its own consumer and stopped by killing that consumer, never by unsubscribing the
 * channel. If another listener closes a channel, it is subscribed again while still wanted.
 */
export default class SocketListeners {
    stopped = false;
    consumers = new Map();

    /**
     * @param {Object} socket the socket service
     */
    constructor(socket) {
        this.socket = socket;
    }

    has(channelId) {
        return this.consumers.has(channelId);
    }

    /**
     * Read a channel, calling `onMessage(message, channelId)` for each message.
     */
    listen(channelId, onMessage) {
        if (this.stopped || !channelId || this.consumers.has(channelId)) {
            return;
        }
        this.consumers.set(channelId, null);
        this.consume(channelId, onMessage);
    }

    async consume(channelId, onMessage) {
        while (!this.stopped && this.consumers.has(channelId)) {
            try {
                const consumer = this.socket.instance().subscribe(channelId).createConsumer();
                this.consumers.set(channelId, consumer);
                for await (const message of consumer) {
                    if (this.stopped) {
                        break;
                    }
                    try {
                        onMessage(message ?? {}, channelId);
                    } catch {
                        // One bad message must not end the stream.
                    }
                }
            } catch {
                // The channel was closed or the socket dropped: subscribe again below.
            }
            if (!this.stopped) {
                await new Promise((resolve) => setTimeout(resolve, 2000));
            }
        }
    }

    stop() {
        this.stopped = true;
        this.consumers.forEach((consumer) => consumer?.kill?.());
        this.consumers.clear();
    }
}
