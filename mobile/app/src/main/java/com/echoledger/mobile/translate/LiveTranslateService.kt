package com.echoledger.mobile.translate

import android.app.Activity
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.content.res.Configuration
import android.media.projection.MediaProjectionManager
import android.os.Build
import android.os.Environment
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import android.os.PowerManager
import android.provider.MediaStore
import androidx.core.app.NotificationCompat
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.R
import com.echoledger.mobile.data.UsageReport
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.UsageWorker
import com.echoledger.mobile.overlay.TranslateBoxActions
import com.echoledger.mobile.overlay.TranslateOverlay
import java.time.Instant
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import java.util.UUID
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient

/**
 * Game and Watch translate (since v1.3, Phase B). One app's sound → [SpeechGate] → [LiveTranslateSession] (Soniox,
 * phone to Soniox directly) → [TranslateOverlay]. Runs as a foreground service with a quiet notification
 * (Pause · Edit / Lock · Hide · Stop). Nothing is stored unless "Save session text" / "Save subtitles" is on, and then
 * only text, in Downloads/EchoLedger.
 *
 * Sound comes from the capture helper's per-app mix when it runs (no prompt, voice chat included), else from Android's
 * screen-audio capture after the person agrees in its dialog (media and game sounds only; voice chat may be missing).
 * It never touches audio focus, the audio mode or the game's volume, and never starts the call recorder.
 */
class LiveTranslateService :
    Service(),
    TranslateBoxActions {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val main = Handler(Looper.getMainLooper())
    private lateinit var machine: BoxMachine
    private var mode = TranslateMode.GAME
    private var packageName: String? = null
    private var source: TranslateAudioSource? = null
    private var session: LiveTranslateSession? = null
    private var gate = SpeechGate()
    private var overlay: TranslateOverlay? = null
    private var prefs = LiveTranslatePrefs()
    private var style = BoxStyle()
    private var target = "en"
    private var updateEveryMs = UPDATE_MS
    private var hideOriginal = false
    private var hotNotice: String? = null
    private var budgetNotice: String? = null
    private var budgetNoticeUntil = 0L
    private var lastSpeechAt = 0L
    private val sentences = CopyOnWriteArrayList<TranslatedSentence>()
    private val elapsed: () -> Long = { System.nanoTime() / 1_000_000 }
    private var thermal: PowerManager.OnThermalStatusChangedListener? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> start(intent)
            ACTION_STOP -> finishAndStop()
            ACTION_PAUSE -> onPause()
            ACTION_RESUME -> onResume()
            ACTION_EDIT -> onGripTap()
            ACTION_HIDE -> onHide()
            ACTION_SHOW -> onShow()
            else -> if (running.value == null) stopSelf()
        }
        return START_NOT_STICKY
    }

    private fun start(intent: Intent) {
        val wanted = TranslateMode.of(intent.getStringExtra(EXTRA_MODE)) ?: TranslateMode.GAME
        if (running.value != null) {
            if (running.value == wanted) return
            finishSession()
        }
        mode = wanted
        machine = BoxMachine(elapsed)
        val projectionData = if (Build.VERSION.SDK_INT >= 33) {
            intent.getParcelableExtra(EXTRA_PROJECTION, Intent::class.java)
        } else {
            @Suppress("DEPRECATION")
            intent.getParcelableExtra(EXTRA_PROJECTION)
        }
        val type = if (projectionData !=
            null
        ) {
            ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PROJECTION
        } else {
            ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
        }
        createChannel(this)
        runCatching { startForeground(NOTIFICATION_ID, notification(), type) }.onFailure {
            stopSelf()
            return
        }
        running.value = mode
        val graph = (application as EchoLedgerApp).graph
        scope.launch {
            val settings = graph.settings.current()
            prefs = settings.liveTranslate
            val usage = TranslateUsage(this@LiveTranslateService)
            val budget = TranslateBudgetGuard.state(usage.spentUsd(), prefs.monthlyBudgetUsd, prefs.budgetOverrideMonth, usage.month())
            if (budget == BudgetState.PAUSED) {
                problem(TranslateBudgetGuard.message(budget, usage.spentUsd(), prefs.monthlyBudgetUsd)!!)
                return@launch
            }
            if (budget == BudgetState.WARN) {
                budgetNotice = TranslateBudgetGuard.message(budget, usage.spentUsd(), prefs.monthlyBudgetUsd)
                budgetNoticeUntil = elapsed() + 6_000
            }
            val speed: SpeedPreset
            val hints: List<String>
            if (mode == TranslateMode.GAME) {
                target = prefs.game.target
                speed = SpeedPreset.of(prefs.game.speed)
                hints = prefs.game.spoken
            } else {
                target = prefs.watch.target
                speed = SpeedPreset.of(prefs.watch.speed)
                hints = prefs.watch.spoken
            }
            graph.helper.setGames(prefs.game.games.toSet())
            val picked = intent.getStringExtra(EXTRA_PACKAGE)
            val helper = graph.helper.helper
            val players = if (helper != null) AudioProbe.parse(runCatching { helper.players() }.getOrNull()) else emptyList()
            val app = TranslateTargets.choose(mode, picked, if (mode == TranslateMode.GAME) prefs.game.games else prefs.watch.apps, players)
            packageName = app?.packageName ?: picked
            if (mode == TranslateMode.WATCH && app != null && !app.capturable) {
                problem(AudioProbe.NOT_ALLOWED)
                return@launch
            }
            // An app picked but not playing yet: its uid from the package (the helper armed its mix already).
            val appUid =
                app?.uid?.takeIf { it > 0 } ?: packageName?.let { runCatching { packageManager.getApplicationInfo(it, 0).uid }.getOrNull() }
            style = if (mode == TranslateMode.GAME) prefs.game.perGame[packageName] ?: prefs.game.style else BoxStyle()
            val chosen: TranslateAudioSource? = when {
                projectionData != null -> {
                    val manager = getSystemService(MediaProjectionManager::class.java)
                    val projection = runCatching { manager.getMediaProjection(Activity.RESULT_OK, projectionData) }.getOrNull()
                    projection?.let { ProjectionSource(it, appUid) }
                }

                helper != null && appUid != null -> HelperAppSource(helper, appUid, 0, voiceChat = mode == TranslateMode.GAME)

                else -> null
            }
            if (chosen == null) {
                problem(
                    if (mode == TranslateMode.GAME && prefs.game.games.isEmpty()) {
                        "Choose your games in Game translate first."
                    } else if (helper == null) {
                        "Start the capture helper (Calls & meetings → Microphone → Set up), or start Live Translate from the app to share the sound."
                    } else {
                        "Nothing is playing yet. Start the game or video first, then Live Translate."
                    }
                )
                return@launch
            }
            gate = SpeechGate(mixed = mode == TranslateMode.WATCH || chosen.kind == "projection")
            val client = OkHttpClient.Builder().pingInterval(20, TimeUnit.SECONDS).build()
            val tokenMode = mode.key
            session = LiveTranslateSession(
                client,
                scope,
                speed,
                fetchToken = {
                    val api = graph.api() ?: throw TokenUnavailable("Pair this phone with your EchoLedger server first.", permanent = true)
                    try {
                        api.liveTranslateToken(tokenMode, target, hints, continueOverBudget = prefs.budgetOverrideMonth == usage.month())
                    } catch (e: ApiException) {
                        throw TokenUnavailable(e.message, permanent = e.status in setOf(403, 503) || e.code == "budget_exceeded")
                    }
                },
                onSentence = { sentences += it },
                onOpenMs = { ms -> reportUsage(ms) }
            )
            val problemText = chosen.start({ frame -> onFrame(frame) }, { main.post { if (running.value != null) finishAndStop() } })
            if (problemText != null) {
                problem(problemText)
                return@launch
            }
            source = chosen
            main.post { showOverlay() }
        }
    }

    private fun onFrame(frame: ShortArray) {
        if (!machine.listening) return
        val s = session ?: return
        val wasSpeaking = gate.speaking
        val result = gate.offer(frame)
        if (result.frames.isNotEmpty()) {
            lastSpeechAt = elapsed()
            s.send(result.frames)
        }
        if (wasSpeaking && !result.speaking) s.speechEnded()
        if (gate.quietFrames == REST_AFTER_FRAMES) s.rest()
        if (mode == TranslateMode.WATCH && gate.quietFrames >= WATCH_STOP_FRAMES) {
            main.post { finishAndStop("Watch translate stopped: nobody spoke for 2 minutes.") }
        }
    }

    private fun showOverlay() {
        if (running.value == null) return
        val o = TranslateOverlay(this, subtitles = mode == TranslateMode.WATCH, actions = this)
        val landscape = resources.configuration.orientation == Configuration.ORIENTATION_LANDSCAPE
        val placement = prefs.placements[BoxGeometry.key(packageName, landscape)]
        if (!o.show(placement, style.opacity, prefs.showGrip)) {
            problem("Allow EchoLedger to display over other apps to see Live Translate.")
            return
        }
        overlay = o
        applyStyle()
        startThermalGuard()
        main.post(ticker)
        watchSettings()
    }

    private fun applyStyle() {
        val o = overlay ?: return
        if (mode == TranslateMode.WATCH) {
            val sub = prefs.watch.style.sanitized()
            o.style(sub.textSp, sub.lines, BoxBackground.NONE, sub.showOriginal && !hideOriginal, target, 1f, subtitleBox = sub.box)
            o.offsetPx = (sub.offsetDp * resources.displayMetrics.density).toInt()
        } else {
            val s = style.sanitized()
            o.style(s.textSp, s.lines, s.backgroundKind, s.showOriginal && !hideOriginal, target, s.opacity)
        }
    }

    /** At most 10 updates a second (4 when the phone is hot): the box shows the newest text. */
    private val ticker = object : Runnable {
        override fun run() {
            val o = overlay ?: return
            val s = session
            val now = elapsed()
            val before = machine.state
            o.state(machine.on(BoxEvent.Tick(now)))
            // Edit locked itself, or the box came back after 5 minutes: the notification's buttons follow.
            if (machine.state != before) updateNotification()
            if (s != null) {
                val feed = s.feed.value
                // The session ended by itself (no Soniox credit, no key, the budget used up): stop and say why.
                if (feed.status == TranslateFeed.Status.STOPPED) {
                    finishAndStop(feed.notice)
                    return
                }
                val fadeAfter = (if (mode == TranslateMode.WATCH) 4 else style.fadeAfterSeconds) * 1_000L
                val recent = maxOf(feed.heardAt, lastSpeechAt)
                val quiet = fadeAfter > 0 && now - recent > fadeAfter
                val notice = hotNotice ?: budgetNotice?.takeIf { now < budgetNoticeUntil } ?: feed.notice
                val waiting = gate.speaking && feed.partial.isBlank() && now - feed.heardAt > 1_000
                val shown = if (mode == TranslateMode.GAME && prefs.game.profanityMask) {
                    feed.copy(text = ProfanityMask.mask(feed.text), partial = ProfanityMask.mask(feed.partial))
                } else {
                    feed
                }
                o.show(shown, visibleText = !quiet || notice != null, waiting = waiting, notice = notice)
            }
            main.postDelayed(this, updateEveryMs)
        }
    }

    private fun startThermalGuard() {
        if (Build.VERSION.SDK_INT < 29) return
        val pm = getSystemService(PowerManager::class.java)
        val listener = PowerManager.OnThermalStatusChangedListener { status ->
            when {
                status >= PowerManager.THERMAL_STATUS_CRITICAL -> {
                    hotNotice = "Paused: the phone is too hot."
                    machine.on(BoxEvent.Pause)
                }

                status >= PowerManager.THERMAL_STATUS_SEVERE -> {
                    updateEveryMs = HOT_UPDATE_MS
                    hideOriginal = true
                    hotNotice = null
                    applyStyle()
                }

                else -> {
                    updateEveryMs = UPDATE_MS
                    if (hideOriginal) {
                        hideOriginal = false
                        applyStyle()
                    }
                    if (hotNotice != null) {
                        hotNotice = null
                        machine.on(BoxEvent.Resume)
                    }
                }
            }
        }
        pm.addThermalStatusListener(listener)
        thermal = listener
    }

    private fun reportUsage(ms: Long) {
        TranslateUsage(this).add(mode, ms)
        UsageWorker.report(
            this,
            UsageReport(
                reportId = "lt-" + UUID.randomUUID().toString().take(24),
                useCase = if (mode == TranslateMode.GAME) "game_translate" else "watch_translate",
                provider = "soniox",
                streamedMs = ms,
                channels = 1,
                endedAt = Instant.now().toString()
            )
        )
    }

    /** Says what went wrong in the notification (never a toast or a popup over the game) and stops. */
    private fun problem(text: String) {
        main.post {
            val nm = getSystemService(NotificationManager::class.java)
            nm.notify(NOTIFICATION_PROBLEM_ID, simple(text))
            finishAndStop()
        }
    }

    private fun finishSession() {
        main.removeCallbacks(ticker)
        settingsJob?.cancel()
        settingsJob = null
        thermal?.let { if (Build.VERSION.SDK_INT >= 29) getSystemService(PowerManager::class.java).removeThermalStatusListener(it) }
        thermal = null
        source?.stop()
        source = null
        session?.stop()
        session = null
        overlay?.hideAll()
        overlay = null
        saveText()
        running.value = null
    }

    private fun finishAndStop(note: String? = null) {
        finishSession()
        note?.let { getSystemService(NotificationManager::class.java).notify(NOTIFICATION_PROBLEM_ID, simple(it)) }
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf()
    }

    /** "Save session text" (Game) or "Save subtitles (.srt)" (Watch): text only, to Downloads/EchoLedger. */
    private fun saveText() {
        val save = if (mode == TranslateMode.GAME) prefs.game.saveText else prefs.watch.saveSrt
        val lines = sentences.toList()
        sentences.clear()
        if (!save || lines.isEmpty() || Build.VERSION.SDK_INT < 29) return
        val stamp = LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH-mm"))
        val app = packageName?.let { TranslateApps.label(this, it) } ?: mode.label
        val (name, mime, body) = if (mode == TranslateMode.WATCH) {
            Triple("$app $stamp.srt", "application/x-subrip", SrtWriter.srt(lines))
        } else {
            Triple("$app $stamp.txt", "text/plain", SrtWriter.text(lines, app))
        }
        runCatching {
            val values = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, name)
                put(MediaStore.Downloads.MIME_TYPE, mime)
                put(MediaStore.Downloads.RELATIVE_PATH, Environment.DIRECTORY_DOWNLOADS + "/EchoLedger")
            }
            val uri = contentResolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, values) ?: return
            contentResolver.openOutputStream(uri)?.use { it.write(body.toByteArray()) }
            getSystemService(NotificationManager::class.java).notify(NOTIFICATION_PROBLEM_ID, simple("Saved to Downloads/EchoLedger/$name"))
        }
    }

    override fun onDestroy() {
        if (running.value != null) finishSession()
        scope.cancel()
        super.onDestroy()
    }

    override fun onConfigurationChanged(newConfig: Configuration) {
        super.onConfigurationChanged(newConfig)
        // A new orientation has its own remembered spot.
        val o = overlay ?: return
        o.hideAll()
        overlay = null
        main.removeCallbacks(ticker)
        showOverlay()
    }

    // ---- box actions ----------------------------------------------------------------------------------------------

    override fun onGripTap() {
        overlay?.state(machine.on(BoxEvent.GripTap))
        updateNotification()
    }

    override fun onDone() {
        overlay?.state(machine.on(BoxEvent.Done))
        updateNotification()
    }

    override fun onTouched() {
        machine.on(BoxEvent.Touched)
    }

    override fun onPause() {
        overlay?.state(machine.on(BoxEvent.Pause))
        session?.rest()
        updateNotification()
    }

    override fun onResume() {
        overlay?.state(machine.on(BoxEvent.Resume))
        updateNotification()
    }

    override fun onHide() {
        overlay?.state(machine.on(BoxEvent.Hide))
        updateNotification()
    }

    private var settingsJob: Job? = null

    /**
     * Changes made in Settings while Live Translate runs apply at once (since 1.3.0.1): the grip dot on or off, the
     * box's style. Before, they waited for the next start.
     */
    private fun watchSettings() {
        val graph = (application as EchoLedgerApp).graph
        settingsJob?.cancel()
        settingsJob = scope.launch {
            graph.settings.settings.drop(1).collect { s ->
                val lt = s.liveTranslate
                main.post {
                    if (running.value == null) return@post
                    prefs = lt
                    overlay?.showGrip(lt.showGrip)
                    if (mode == TranslateMode.GAME) style = lt.game.perGame[packageName] ?: lt.game.style
                    applyStyle()
                }
            }
        }
    }

    private fun onShow() {
        overlay?.state(machine.on(BoxEvent.Show))
        updateNotification()
    }

    override fun onStop() {
        finishAndStop()
    }

    override fun onTextSize(step: Int) {
        style = style.copy(textSp = style.textSp + step).sanitized()
        applyStyle()
        rememberStyle()
    }

    override fun onOpacity(step: Float) {
        style = style.copy(opacity = style.opacity + step).sanitized()
        applyStyle()
        rememberStyle()
    }

    override fun onToggleOriginal() {
        style = style.copy(showOriginal = !style.showOriginal)
        applyStyle()
        rememberStyle()
    }

    override fun onLanguage() {
        val list = TranslateLanguages.TARGETS.map { it.code }
        target = list[(list.indexOf(target) + 1).mod(list.size)]
        // A new target needs a new stream: the next speech opens one with it.
        session?.rest()
        val graph = (application as EchoLedgerApp).graph
        val chosen = target
        scope.launch {
            graph.settings.update {
                val lt = it.liveTranslate
                it.copy(
                    liveTranslate = if (mode == TranslateMode.GAME) {
                        lt.copy(game = lt.game.copy(target = chosen))
                    } else {
                        lt.copy(watch = lt.watch.copy(target = chosen))
                    }
                )
            }
        }
        budgetNotice = "Translating to ${TranslateLanguages.label(target)}"
        budgetNoticeUntil = elapsed() + 2_000
        applyStyle()
    }

    override fun onPlaced(placement: BoxPlacement) {
        val landscape = resources.configuration.orientation == Configuration.ORIENTATION_LANDSCAPE
        val key = BoxGeometry.key(packageName, landscape)
        val graph = (application as EchoLedgerApp).graph
        scope.launch {
            graph.settings.update {
                it.copy(
                    liveTranslate = it.liveTranslate.copy(
                        placements =
                            it.liveTranslate.placements + (key to placement)
                    )
                )
            }
        }
    }

    /** Text size, opacity and the original line are remembered per game (plan B3: a per-game profile). */
    private fun rememberStyle() {
        if (mode != TranslateMode.GAME) return
        val pkg = packageName ?: return
        val chosen = style
        val graph = (application as EchoLedgerApp).graph
        scope.launch {
            graph.settings.update {
                it.copy(
                    liveTranslate = it.liveTranslate.copy(
                        game = it.liveTranslate.game.copy(
                            perGame =
                                it.liveTranslate.game.perGame + (pkg to chosen)
                        )
                    )
                )
            }
        }
    }

    // ---- notification -------------------------------------------------------------------------------------------------

    private fun notification(): Notification {
        val paused = ::machine.isInitialized && machine.state == BoxState.PAUSED
        val editing = ::machine.isInitialized && machine.state == BoxState.EDIT
        val hidden = ::machine.isInitialized && machine.state == BoxState.HIDDEN
        return NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(mode.label)
            .setContentText(if (paused) "Paused" else "Translating to ${TranslateLanguages.label(target)}")
            .setOngoing(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setContentIntent(open(this))
            .addAction(0, if (paused) "Resume" else "Pause", action(if (paused) ACTION_RESUME else ACTION_PAUSE, 1))
            .addAction(0, if (editing) "Lock" else "Edit", action(ACTION_EDIT, 2))
            .addAction(0, if (hidden) "Show" else "Hide", action(if (hidden) ACTION_SHOW else ACTION_HIDE, 3))
            .addAction(0, "Stop", action(ACTION_STOP, 4))
            .build()
    }

    private fun simple(text: String): Notification = NotificationCompat.Builder(this, CHANNEL)
        .setSmallIcon(R.drawable.ic_stat_rec)
        .setContentTitle(mode.label)
        .setContentText(text)
        .setStyle(NotificationCompat.BigTextStyle().bigText(text))
        .setSilent(true)
        .setAutoCancel(true)
        .setContentIntent(open(this))
        .build()

    private fun updateNotification() {
        if (running.value == null) return
        getSystemService(NotificationManager::class.java).notify(NOTIFICATION_ID, notification())
    }

    private fun action(name: String, code: Int): PendingIntent = PendingIntent.getService(
        this,
        code,
        Intent(this, LiveTranslateService::class.java).setAction(name),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

    companion object {
        const val ACTION_START = "com.echoledger.mobile.translate.START"
        const val ACTION_STOP = "com.echoledger.mobile.translate.STOP"
        const val ACTION_PAUSE = "com.echoledger.mobile.translate.PAUSE"
        const val ACTION_RESUME = "com.echoledger.mobile.translate.RESUME"
        const val ACTION_EDIT = "com.echoledger.mobile.translate.EDIT"
        const val ACTION_HIDE = "com.echoledger.mobile.translate.HIDE"
        const val ACTION_SHOW = "com.echoledger.mobile.translate.SHOW"
        const val EXTRA_MODE = "mode"
        const val EXTRA_PACKAGE = "package"
        const val EXTRA_PROJECTION = "projection"
        const val CHANNEL = "live_translate"
        const val NOTIFICATION_ID = 1201
        const val NOTIFICATION_PROBLEM_ID = 1202
        const val UPDATE_MS = 100L
        const val HOT_UPDATE_MS = 250L

        /** 30 s without speech closes the Soniox stream (it bills open time); the next word opens it again. */
        const val REST_AFTER_FRAMES = 300

        /** Watch translate stops after 2 minutes without speech (plan B4). */
        const val WATCH_STOP_FRAMES = 1_200

        /** The mode running now, or null. */
        val running = MutableStateFlow<TranslateMode?>(null)
        val state: StateFlow<TranslateMode?> get() = running

        fun startIntent(context: Context, mode: TranslateMode, packageName: String? = null, projection: Intent? = null): Intent =
            Intent(context, LiveTranslateService::class.java).setAction(ACTION_START)
                .putExtra(EXTRA_MODE, mode.key)
                .apply {
                    packageName?.let { putExtra(EXTRA_PACKAGE, it) }
                    projection?.let { putExtra(EXTRA_PROJECTION, it) }
                }

        fun stop(context: Context) {
            context.startService(Intent(context, LiveTranslateService::class.java).setAction(ACTION_STOP))
        }

        fun createChannel(context: Context) {
            context.getSystemService(NotificationManager::class.java).createNotificationChannel(
                NotificationChannel(CHANNEL, context.getString(R.string.channel_live_translate), NotificationManager.IMPORTANCE_LOW).apply {
                    setShowBadge(false)
                    setSound(null, null)
                    enableVibration(false)
                }
            )
        }

        private fun open(context: Context): PendingIntent = PendingIntent.getActivity(
            context,
            0,
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
    }
}

/** Which app Live Translate listens to (plan B3/B4). Pure, so it is tested without a phone. */
object TranslateTargets {
    /**
     * The app to translate: the one asked for, else a picked app that is playing now (voice chat first for games),
     * else for Watch "whatever is playing" (media first), else the first picked app.
     */
    fun choose(mode: TranslateMode, asked: String?, picked: List<String>, players: List<ProbePlayer>): ProbePlayer? {
        val playing = players.filter { it.playing && it.uid > 0 }
        fun forPackage(pkg: String) = playing.filter { pkg in it.packages }.sortedBy { if (it.voiceChat) 0 else 1 }.firstOrNull()
        asked?.let { pkg ->
            return forPackage(pkg)
                ?: ProbePlayer(-1, listOf(pkg), 0, 0, android.media.AudioAttributes.ALLOW_CAPTURE_BY_ALL, -1)
        }
        picked.firstNotNullOfOrNull { forPackage(it) }?.let { return it }
        if (mode == TranslateMode.WATCH && picked.isEmpty()) {
            return playing.filter { !it.voiceChat }.sortedBy {
                if (it.usage ==
                    android.media.AudioAttributes.USAGE_MEDIA
                ) {
                    0
                } else {
                    1
                }
            }.firstOrNull()
        }
        return picked.firstOrNull()?.let { ProbePlayer(-1, listOf(it), 0, 0, android.media.AudioAttributes.ALLOW_CAPTURE_BY_ALL, -1) }
    }
}
