package com.echoledger.mobile.widget

import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.compose.runtime.Composable
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.DpSize
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.GlanceTheme
import androidx.glance.ImageProvider
import androidx.glance.LocalContext
import androidx.glance.LocalSize
import androidx.glance.action.ActionParameters
import androidx.glance.action.actionParametersOf
import androidx.glance.action.actionStartActivity
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.appwidget.SizeMode
import androidx.glance.appwidget.action.actionStartService
import androidx.glance.appwidget.components.CircleIconButton
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.provideContent
import androidx.glance.appwidget.updateAll
import androidx.glance.background
import androidx.glance.color.ColorProviders
import androidx.glance.color.DynamicThemeColorProviders
import androidx.glance.layout.Alignment
import androidx.glance.layout.Box
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxHeight
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.layout.size
import androidx.glance.layout.width
import androidx.glance.material3.ColorProviders
import androidx.glance.semantics.contentDescription
import androidx.glance.semantics.semantics
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.R
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.DarkColors
import com.echoledger.mobile.ui.LightColors
import com.echoledger.mobile.ui.toScheme

/**
 * Home-screen widget in three sizes (C4): 2 x 1 (record button and status), 2 x 2 (status, review count, last
 * recording) and 4 x 2 (everything side by side). The Record button starts the recording service through a widget
 * interaction (a guaranteed exemption for starting a microphone foreground service). Colours are the app's tokens,
 * or the wallpaper's when the app uses them.
 */
class RecordWidget : GlanceAppWidget() {
    override val sizeMode: SizeMode = SizeMode.Responsive(setOf(SMALL, SQUARE, WIDE, LARGE))

    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val state = WidgetStateStore.load(context)
        provideContent {
            GlanceTheme(colors = widgetColors(state.dynamicColour)) { WidgetContent(state) }
        }
    }

    companion object {
        val SMALL = DpSize(110.dp, 40.dp)
        val SQUARE = DpSize(110.dp, 110.dp)
        val WIDE = DpSize(250.dp, 110.dp)

        /** 4 x 4 (since v1.6): the last six recordings, each one tap to open. */
        val LARGE = DpSize(250.dp, 250.dp)
    }
}

/** EchoLedger's light and dark tokens, or the wallpaper's colours (Android 12+) when the app uses them. */
internal fun widgetColors(dynamic: Boolean): ColorProviders = if (dynamic && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
    DynamicThemeColorProviders
} else {
    ColorProviders(light = LightColors.toScheme(), dark = DarkColors.toScheme())
}

@Composable
internal fun WidgetContent(state: WidgetState) {
    val size = LocalSize.current
    Box(
        GlanceModifier.fillMaxSize().background(GlanceTheme.colors.surface).cornerRadius(16.dp)
            .padding(horizontal = 12.dp, vertical = if (size.height < 100.dp) 6.dp else 12.dp)
    ) {
        when {
            size.height < 100.dp -> SmallLayout(state)
            size.width >= 230.dp && size.height >= 230.dp -> LargeLayout(state)
            size.width >= 230.dp -> WideLayout(state)
            else -> SquareLayout(state)
        }
    }
}

@Composable
private fun SmallLayout(state: WidgetState) {
    Row(GlanceModifier.fillMaxSize(), verticalAlignment = Alignment.CenterVertically) {
        RecordButton(state, 36.dp)
        Spacer(GlanceModifier.width(10.dp))
        Column(GlanceModifier.defaultWeight().clickable(actionStartActivity<MainActivity>())) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                ReadyDot(state)
                StatusText(state, maxLines = 1)
            }
        }
    }
}

@Composable
private fun SquareLayout(state: WidgetState) {
    Column(GlanceModifier.fillMaxSize()) {
        AppName(state)
        StatusText(state, maxLines = 2)
        ReviewText(state)
        Spacer(GlanceModifier.defaultWeight())
        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Spacer(GlanceModifier.defaultWeight())
            RecordButton(state, 44.dp)
        }
    }
}

@Composable
private fun WideLayout(state: WidgetState) {
    Row(GlanceModifier.fillMaxSize(), verticalAlignment = Alignment.CenterVertically) {
        Column(GlanceModifier.defaultWeight().fillMaxHeight()) {
            AppName(state)
            StatusText(state, maxLines = 1)
            ReviewText(state)
            Spacer(GlanceModifier.height(6.dp))
            // Since v1.6: the last two with their progress; before the first refresh, the last one as before.
            if (state.showsContent && state.recent.isNotEmpty()) {
                state.recent.take(2).forEach { RecentRow(it) }
            } else {
                LastRecording(state)
            }
        }
        Spacer(GlanceModifier.width(12.dp))
        RecordButton(state, 52.dp)
    }
}

/** 4 x 4 (since v1.6, Phase 1C I4): the status and Record now on top, then the last six, each one tap to open. */
@Composable
private fun LargeLayout(state: WidgetState) {
    Column(GlanceModifier.fillMaxSize()) {
        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Column(GlanceModifier.defaultWeight()) {
                AppName(state)
                StatusText(state, maxLines = 1)
                ReviewText(state)
            }
            Spacer(GlanceModifier.width(12.dp))
            RecordButton(state, 52.dp)
        }
        Spacer(GlanceModifier.height(8.dp))
        if (state.showsContent) {
            state.recent.take(6).forEach { RecentRow(it) }
            if (state.recent.isEmpty()) LastRecording(state)
        }
    }
}

/** One recent recording: its title (or its app, with Hide titles) and its progress or time; a tap opens it. */
@Composable
private fun RecentRow(row: WidgetRow) {
    Column(GlanceModifier.fillMaxWidth().padding(vertical = 3.dp).clickable(openSession(row.id))) {
        Text(
            row.title,
            maxLines = 1,
            style = TextStyle(color = GlanceTheme.colors.onSurface, fontSize = 13.sp, fontWeight = FontWeight.Medium)
        )
        Text(row.line, maxLines = 1, style = TextStyle(color = GlanceTheme.colors.onSurfaceVariant, fontSize = 12.sp))
    }
}

/** The Ready check's dot (since v1.6): green when calls are recorded, red when not, grey when switched off. */
@Composable
private fun ReadyDot(state: WidgetState) {
    val label = state.readyLabel ?: return
    val colour = when (state.ready) {
        "READY" -> androidx.glance.color.ColorProvider(day = READY_DAY, night = READY_NIGHT)
        "NOT_READY" -> GlanceTheme.colors.error
        else -> GlanceTheme.colors.outline
    }
    Box(GlanceModifier.size(8.dp).cornerRadius(4.dp).background(colour).semantics { contentDescription = label }) {}
    Spacer(GlanceModifier.width(6.dp))
}

private val READY_DAY = androidx.compose.ui.graphics.Color(0xFF15803D)
private val READY_NIGHT = androidx.compose.ui.graphics.Color(0xFF4ADE80)

@Composable
private fun AppName(state: WidgetState) {
    Row(GlanceModifier.clickable(actionStartActivity<MainActivity>()), verticalAlignment = Alignment.CenterVertically) {
        ReadyDot(state)
        Text(
            "EchoLedger",
            maxLines = 1,
            style = TextStyle(color = GlanceTheme.colors.onSurface, fontWeight = FontWeight.Medium, fontSize = 13.sp)
        )
    }
}

@Composable
private fun StatusText(state: WidgetState, maxLines: Int) {
    Text(
        state.statusLine,
        maxLines = maxLines,
        style = TextStyle(
            color = if (state.recording) GlanceTheme.colors.error else GlanceTheme.colors.onSurfaceVariant,
            fontSize = 12.sp,
            fontWeight = if (state.recording) FontWeight.Medium else FontWeight.Normal
        ),
        modifier = when {
            state.recording -> GlanceModifier
            state.pendingDecisions > 0 -> GlanceModifier.clickable(openPending())
            state.notReady -> GlanceModifier.clickable(openReady())
            else -> GlanceModifier
        }
    )
}

@Composable
private fun ReviewText(state: WidgetState) {
    state.reviewLine?.let {
        Text(
            it,
            maxLines = 1,
            style = TextStyle(color = GlanceTheme.colors.primary, fontSize = 12.sp, fontWeight = FontWeight.Medium),
            modifier = GlanceModifier.clickable(openPending())
        )
    }
}

@Composable
private fun LastRecording(state: WidgetState) {
    if (!state.showsContent) return
    state.lastTitle?.let {
        Text(it, maxLines = 1, style = TextStyle(color = GlanceTheme.colors.onSurface, fontSize = 13.sp, fontWeight = FontWeight.Medium))
    }
    state.lastPreview?.let {
        Text(it, maxLines = 2, style = TextStyle(color = GlanceTheme.colors.onSurfaceVariant, fontSize = 12.sp))
    }
}

/** Record / Stop as a round button (nothing before pairing: the status line says to open the app). */
@Composable
private fun RecordButton(state: WidgetState, size: Dp) {
    if (!state.paired) return
    val context = LocalContext.current
    val toggle = RecordingService.intent(context, RecordingService.ACTION_TOGGLE, from = "home-screen widget")
    CircleIconButton(
        imageProvider = ImageProvider(if (state.recording) R.drawable.ic_widget_stop else R.drawable.ic_stat_rec),
        contentDescription = state.buttonLabel,
        onClick = actionStartService(toggle, isForegroundService = true),
        modifier = GlanceModifier.size(size),
        backgroundColor = if (state.recording) GlanceTheme.colors.error else GlanceTheme.colors.primary,
        contentColor = if (state.recording) GlanceTheme.colors.onError else GlanceTheme.colors.onPrimary
    )
}

/** Opens one recording (since v1.6). */
private fun openSession(id: String) = actionStartActivity<MainActivity>(
    actionParametersOf(ActionParameters.Key<String>(MainActivity.EXTRA_OPEN) to MainActivity.OPEN_SESSION_PREFIX + id)
)

/** Opens the Ready check, where each problem has its fix (since v1.6). */
private fun openReady() = actionStartActivity<MainActivity>(
    actionParametersOf(ActionParameters.Key<String>(MainActivity.EXTRA_OPEN) to MainActivity.OPEN_READY)
)

/** Opens the Record tab's list of recordings waiting for Keep / Discard (glance passes parameters as intent extras). */
private fun openPending() = actionStartActivity<MainActivity>(
    actionParametersOf(ActionParameters.Key<String>(MainActivity.EXTRA_OPEN) to MainActivity.OPEN_PENDING)
)

class RecordWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = RecordWidget()
}

/** Widget state cache (small JSON in private prefs) + refresh helper. */
object WidgetStateStore {
    private const val PREFS = "echoledger_widget"
    private const val KEY = "state"

    fun load(context: Context): WidgetState = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, null)
        ?.let { runCatching { ApiJson.decodeFromString(WidgetState.serializer(), it) }.getOrNull() } ?: WidgetState()

    fun save(context: Context, state: WidgetState) {
        context.getSharedPreferences(
            PREFS,
            Context.MODE_PRIVATE
        ).edit().putString(KEY, ApiJson.encodeToString(WidgetState.serializer(), state)).apply()
    }

    suspend fun refresh(context: Context) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        val settings = graph.settings.current()
        // With App lock on, no titles or transcript text are stored for (or shown on) the home screen; with Hide titles
        // (since v1.6) a recording is named by its app only and no transcript text is kept.
        val shown = if (settings.appLockEnabled) emptyList() else graph.library.items.value.filter { !it.deleted }
        val latest = shown.firstOrNull()
        save(
            context,
            WidgetState(
                recording = RecordingService.isRecording,
                armed = settings.autoRecordArmed,
                paired = settings.paired,
                lastTitle = latest?.let { if (settings.widgetHideTitles) null else it.title },
                lastPreview = latest?.takeUnless { settings.widgetHideTitles }?.transcript?.preview,
                pendingDecisions = graph.recordings.pendingDecisions().size,
                locked = settings.appLockEnabled,
                dynamicColour = settings.dynamicColour,
                recent = recentRows(shown, settings.widgetHideTitles, java.time.LocalDate.now()),
                ready = com.echoledger.mobile.recording.ReadinessWatch.lastChip(context)
            )
        )
        runCatching { RecordWidget().updateAll(context) }
    }

    /** The latest six, newest first: the title (or the app with [hideTitles]), and the progress, a problem or the time. */
    fun recentRows(items: List<com.echoledger.mobile.data.LibraryItem>, hideTitles: Boolean, today: java.time.LocalDate): List<WidgetRow> =
        items.sortedByDescending { it.startedAt.orEmpty() }.take(6).map { item ->
            val app = com.echoledger.mobile.core.SourceApp.from(item.sourceApp)
            val generic = com.echoledger.mobile.ui.design.untitled(app)
            val title = if (hideTitles) generic else item.title?.takeIf { it.isNotBlank() } ?: generic
            val line = when (com.echoledger.mobile.core.ItemStatus.of(item)) {
                com.echoledger.mobile.core.ItemStatus.FAILED -> "Transcription failed"

                com.echoledger.mobile.core.ItemStatus.NO_AUDIO -> "No audio"

                else -> com.echoledger.mobile.core.RowProgress.of(item)?.text
                    ?: com.echoledger.mobile.ui.design.rowTime(item.startedAt, today)
            }
            WidgetRow(item.id, title, line)
        }
}
