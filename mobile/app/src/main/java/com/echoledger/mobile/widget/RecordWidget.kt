package com.echoledger.mobile.widget

import android.content.Context
import android.content.Intent
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.Button
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.GlanceTheme
import androidx.glance.LocalContext
import androidx.glance.action.ActionParameters
import androidx.glance.action.actionParametersOf
import androidx.glance.action.actionStartActivity
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.appwidget.action.actionStartService
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.provideContent
import androidx.glance.appwidget.updateAll
import androidx.glance.background
import androidx.glance.layout.Alignment
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.recording.RecordingService

/**
 * Home-screen widget: quick record, status and the latest transcript. The Record button starts the recording service
 * through a widget interaction (a guaranteed exemption for starting a microphone foreground service).
 */
class RecordWidget : GlanceAppWidget() {
    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val state = WidgetStateStore.load(context)
        provideContent {
            GlanceTheme { Content(state) }
        }
    }
}

@androidx.compose.runtime.Composable
private fun Content(state: WidgetState) {
    val context = LocalContext.current
    val toggle = Intent(context, RecordingService::class.java).setAction(RecordingService.ACTION_TOGGLE)
    Column(
        modifier = GlanceModifier.fillMaxSize().background(Color(0xFF12141C)).cornerRadius(20.dp).padding(14.dp),
        verticalAlignment = Alignment.Top
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, modifier = GlanceModifier.clickable(actionStartActivity<MainActivity>())) {
            Text("EchoLedger", style = TextStyle(color = ColorProvider(Color(0xFFE6E8EE)), fontWeight = FontWeight.Bold, fontSize = 15.sp))
        }
        Text(
            state.statusLine,
            style = TextStyle(color = ColorProvider(if (state.recording) Color(0xFFF43F5E) else Color(0xFF9AA3B2)), fontSize = 12.sp),
            modifier = if (!state.recording && state.pendingDecisions > 0) GlanceModifier.clickable(openPending()) else GlanceModifier
        )
        state.reviewLine?.let {
            Text(
                it,
                style = TextStyle(color = ColorProvider(Color(0xFF8B5CF6)), fontSize = 12.sp, fontWeight = FontWeight.Medium),
                modifier = GlanceModifier.clickable(openPending())
            )
        }
        Spacer(GlanceModifier.height(8.dp))
        state.lastTitle?.takeIf { state.showsContent }?.let {
            Text(
                it,
                maxLines = 1,
                style = TextStyle(color = ColorProvider(Color(0xFFE6E8EE)), fontSize = 13.sp, fontWeight = FontWeight.Medium)
            )
        }
        state.lastPreview?.takeIf { state.showsContent }?.let {
            Text(it, maxLines = 2, style = TextStyle(color = ColorProvider(Color(0xFF9AA3B2)), fontSize = 12.sp))
        }
        Spacer(GlanceModifier.height(8.dp))
        if (state.paired) {
            Button(text = state.buttonLabel, onClick = actionStartService(toggle, isForegroundService = true))
        }
    }
}

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
        // With App lock on, no titles or transcript text are stored for (or shown on) the home screen.
        val latest = graph.library.items.value.firstOrNull { !it.deleted }?.takeUnless { settings.appLockEnabled }
        save(
            context,
            WidgetState(
                recording = RecordingService.isRecording,
                armed = settings.autoRecordArmed,
                paired = settings.paired,
                lastTitle = latest?.title,
                lastPreview = latest?.transcript?.preview,
                pendingDecisions = graph.recordings.pendingDecisions().size,
                locked = settings.appLockEnabled
            )
        )
        runCatching { RecordWidget().updateAll(context) }
    }
}
