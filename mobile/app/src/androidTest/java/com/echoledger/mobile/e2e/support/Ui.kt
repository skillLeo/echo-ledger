package com.echoledger.mobile.e2e.support

import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Rect
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.By
import androidx.test.uiautomator.BySelector
import androidx.test.uiautomator.StaleObjectException
import androidx.test.uiautomator.UiDevice
import androidx.test.uiautomator.UiObject2
import androidx.test.uiautomator.Until
import com.echoledger.mobile.MainActivity
import java.io.File
import java.util.regex.Pattern

/** Black-box helpers: everything here goes through the accessibility tree, like a person using the phone. */
object Ui {
    const val PACKAGE = "com.echoledger.mobile"
    const val SHORT = 8_000L
    const val NORMAL = 30_000L
    const val LONG = 90_000L

    private val instrumentation get() = InstrumentationRegistry.getInstrumentation()
    val device: UiDevice get() = UiDevice.getInstance(instrumentation)
    val context: Context get() = instrumentation.targetContext

    /** Starts the app in a fresh task, as tapping the launcher icon after the previous task was cleared. */
    fun launchApp() {
        context.startActivity(
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
        )
        device.wait(Until.hasObject(By.pkg(PACKAGE).depth(0)), NORMAL)
    }

    /** Brings the existing task back to the front (the recents screen / launcher icon with the app still running). */
    fun resumeApp() {
        context.startActivity(
            Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_REORDER_TO_FRONT)
        )
        device.wait(Until.hasObject(By.pkg(PACKAGE).depth(0)), NORMAL)
    }

    fun find(selector: BySelector): UiObject2? = device.findObject(selector)

    fun has(text: String): Boolean = device.hasObject(By.text(text))

    fun hasContaining(text: String): Boolean = device.hasObject(By.textContains(text))

    fun hasDescription(description: String): Boolean = device.hasObject(By.desc(description))

    fun await(selector: BySelector, timeoutMs: Long = NORMAL, what: String = selector.toString()): UiObject2 =
        device.wait(Until.findObject(selector), timeoutMs)
            ?: throw AssertionError("Timed out after ${timeoutMs}ms waiting for $what\n${hierarchySummary()}")

    fun awaitText(text: String, timeoutMs: Long = NORMAL): UiObject2 = await(By.text(text), timeoutMs, "text \"$text\"")

    fun awaitTextContaining(text: String, timeoutMs: Long = NORMAL): UiObject2 =
        await(By.textContains(text), timeoutMs, "text containing \"$text\"")

    fun awaitDescription(description: String, timeoutMs: Long = NORMAL): UiObject2 =
        await(By.desc(description), timeoutMs, "content description \"$description\"")

    fun awaitGone(selector: BySelector, timeoutMs: Long = NORMAL, what: String = selector.toString()) {
        if (!device.wait(Until.gone(selector), timeoutMs)) {
            throw AssertionError("Still present after ${timeoutMs}ms: $what\n${hierarchySummary()}")
        }
    }

    fun awaitTextGone(text: String, timeoutMs: Long = NORMAL) = awaitGone(By.text(text), timeoutMs, "text \"$text\"")

    /** Polls [condition] until it holds. */
    fun awaitCondition(what: String, timeoutMs: Long = NORMAL, intervalMs: Long = 500, condition: () -> Boolean) {
        val deadline = System.currentTimeMillis() + timeoutMs
        while (System.currentTimeMillis() < deadline) {
            if (condition()) return
            Thread.sleep(intervalMs)
        }
        if (!condition()) throw AssertionError("Timed out after ${timeoutMs}ms waiting for $what\n${hierarchySummary()}")
    }

    /** A node found a moment ago can be replaced by a recomposition before it is clicked; look it up again in that case. */
    private fun retryWhenStale(action: () -> Unit) {
        repeat(3) {
            try {
                return action()
            } catch (e: StaleObjectException) {
                Thread.sleep(300)
            }
        }
        action()
    }

    fun clickText(text: String, timeoutMs: Long = NORMAL) {
        retryWhenStale { awaitText(text, timeoutMs).click() }
    }

    fun clickDescription(description: String, timeoutMs: Long = NORMAL) {
        retryWhenStale { awaitDescription(description, timeoutMs).click() }
    }

    /** Bottom navigation: the three tabs expose their label as the icon's content description. */
    fun openTab(label: String) {
        clickDescription(label)
    }

    /** Text field whose floating label is [label]. */
    fun field(label: String): UiObject2 {
        val labelBounds = awaitText(label).visibleBounds
        return device.findObjects(By.clazz("android.widget.EditText")).firstOrNull { it.visibleBounds.contains(labelBounds) }
            ?: throw AssertionError("No text field labelled \"$label\"\n${hierarchySummary()}")
    }

    fun fillField(label: String, value: String) {
        field(label).apply {
            click()
            text = value
        }
    }

    /** Swipes the screen up until [text] is on screen (content of a scrolling column below the fold is not visible to tools). */
    fun scrollDownTo(text: String) {
        repeat(6) {
            if (has(text)) return
            val x = device.displayWidth / 2
            device.swipe(x, (device.displayHeight * 0.75).toInt(), x, (device.displayHeight * 0.25).toInt(), 25)
            Thread.sleep(500)
        }
        awaitText(text, SHORT)
    }

    fun hideKeyboard() {
        if (Shell.keyboardShown()) device.pressBack()
        awaitCondition("keyboard to hide", SHORT) { !Shell.keyboardShown() }
    }

    /** True when the button labelled [label] can be pressed (its text node sits inside the clickable surface). */
    fun isButtonEnabled(label: String): Boolean = awaitText(label).parent?.isEnabled ?: false

    /** The switch on the same row as the text [label] (the closest checkable node vertically). */
    fun switchNear(label: String): UiObject2 {
        val y = awaitText(label).visibleBounds.centerY()
        return device.findObjects(By.checkable(true)).minByOrNull { Math.abs(it.visibleBounds.centerY() - y) }
            ?: throw AssertionError("No switch near \"$label\"\n${hierarchySummary()}")
    }

    fun clipboardText(): String {
        var text: String? = null
        instrumentation.runOnMainSync {
            val manager = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
            text = manager.primaryClip?.getItemAt(0)?.text?.toString()
        }
        return text.orEmpty()
    }

    fun clearClipboard() {
        instrumentation.runOnMainSync {
            (context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager).clearPrimaryClip()
        }
    }

    fun screenshot(): Bitmap {
        val file = File(context.cacheDir, "screen-${System.nanoTime()}.png")
        check(device.takeScreenshot(file)) { "screenshot failed" }
        return BitmapFactory.decodeFile(file.path).also { file.delete() }
    }

    /** A folder under the app's external media directory, which "clear storage" between tests leaves alone. */
    fun artifactDir(name: String): File = File(context.externalMediaDirs.first(), name).apply { mkdirs() }

    /** Saves a screenshot of the current screen for visual review (`e2e-shots`). */
    fun snapshot(label: String) {
        val dir = artifactDir("e2e-shots")
        runCatching { device.takeScreenshot(File(dir, "${device.displayWidth}x${device.displayHeight}-$label.png")) }
    }

    /** Brightest pixel (sum of channels / 3) inside [area] of the current screen. */
    fun brightest(bitmap: Bitmap, area: Rect): Int {
        var best = 0
        for (x in area.left until area.right) {
            for (y in area.top until area.bottom) {
                val p = bitmap.getPixel(x, y)
                best = maxOf(best, ((p shr 16 and 0xFF) + (p shr 8 and 0xFF) + (p and 0xFF)) / 3)
            }
        }
        return best
    }

    fun dismissSystemDialogs() {
        device.pressHome()
    }

    /** Text of every visible node, for assertion messages. */
    fun hierarchySummary(): String = try {
        val out = java.io.ByteArrayOutputStream()
        device.dumpWindowHierarchy(out)
        Regex("""text="([^"]+)"""").findAll(out.toString()).map { it.groupValues[1] }.distinct().take(60).joinToString(" | ", "visible: ")
    } catch (e: Exception) {
        "visible: (unavailable: ${e.message})"
    }

    fun permissionDialogButton(vararg labels: String): UiObject2? {
        val pattern = Pattern.compile(labels.joinToString("|") { Pattern.quote(it) }, Pattern.CASE_INSENSITIVE)
        return device.findObject(By.text(pattern).pkg(Pattern.compile(".*permissioncontroller")))
    }
}
