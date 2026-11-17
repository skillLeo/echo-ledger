package com.echoledger.mobile.assist

/**
 * The messages waiting for the relay (docs/API_ASSIST.md 1.9.0-o, "What the apps must handle"). While the connection
 * is up they leave in order, paced to the relay's `messages_per_second`; while it is down they wait, bounded, and only
 * what still matters is kept: the latest `turn_partial` and the latest `context_update`, but every `turn_final` and
 * every command. A message whose send failed goes back to the front, so nothing is lost or reordered.
 */
class AssistOutbox(private val maxMessages: Int = MAX_MESSAGES, private val maxBytes: Int = MAX_BYTES) {
    private val queue = ArrayDeque<Pair<ClientMessage, Int>>()
    private var bytes = 0

    /** Messages dropped because the bounds were reached (the oldest first). */
    var dropped = 0
        private set

    val size: Int get() = queue.size

    fun isEmpty(): Boolean = queue.isEmpty()

    /** Adds [message]; while [down], a partial or a context replaces the one of its kind waiting. */
    @Synchronized
    fun push(message: ClientMessage, down: Boolean) {
        if (down && (message is ClientMessage.TurnPartial || message is ClientMessage.ContextUpdate)) {
            val sameKind: (ClientMessage) -> Boolean = if (message is ClientMessage.TurnPartial) {
                { it is ClientMessage.TurnPartial }
            } else {
                { it is ClientMessage.ContextUpdate }
            }
            queue.removeAll { sameKind(it.first) }
            bytes = queue.sumOf { it.second }
        }
        val size = message.frame(0).toByteArray().size
        queue.addLast(message to size)
        bytes += size
        while (queue.size > maxMessages || (bytes > maxBytes && queue.size > 1)) {
            val (_, gone) = queue.removeFirst()
            bytes -= gone
            dropped++
        }
    }

    /** The next message, taken out; null when none waits. */
    @Synchronized
    fun take(): ClientMessage? = queue.removeFirstOrNull()?.also { (_, n) -> bytes -= n }?.first

    /** A relay that lost the session: [whole] goes first, in place of any context waiting. */
    @Synchronized
    fun contextFirst(whole: ClientMessage.ContextUpdate) {
        queue.removeAll { it.first is ClientMessage.ContextUpdate }
        bytes = queue.sumOf { it.second }
        putBack(whole)
    }

    /** A message whose send failed goes back to the front. */
    @Synchronized
    fun putBack(message: ClientMessage) {
        val size = message.frame(0).toByteArray().size
        queue.addFirst(message to size)
        bytes += size
    }

    companion object {
        const val MAX_MESSAGES = 500
        const val MAX_BYTES = 4 * 1024 * 1024

        /** The relay's rate when its `ready` names none. */
        const val DEFAULT_RATE = 40
    }
}

/**
 * What the relay holds of the session's context (`context_update` replaces each field given): kept whole, so a relay
 * that restarted and lost the session (`ready` with `resumed: false` after a context was given) gets all of it again.
 */
class AssistContext {
    private val fields = linkedMapOf<String, kotlinx.serialization.json.JsonElement>()

    val isEmpty: Boolean @Synchronized get() = fields.isEmpty()

    /** Records [update] and returns it to send. */
    @Synchronized
    fun update(update: kotlinx.serialization.json.JsonObject): ClientMessage.ContextUpdate {
        fields.putAll(update)
        return ClientMessage.ContextUpdate(update)
    }

    /** Everything given so far, as one update. */
    @Synchronized
    fun whole(): ClientMessage.ContextUpdate = ClientMessage.ContextUpdate(kotlinx.serialization.json.JsonObject(LinkedHashMap(fields)))
}
