package com.echoledger.mobile.ui.v2.shell

import android.content.Context
import com.echoledger.mobile.ui.MainViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/** The two designs a person can use. */
enum class Design(val wire: String) {
    Classic("classic"),
    V2("v2");

    companion object {
        fun of(wire: String?): Design? = entries.firstOrNull { it.wire == wire }
    }
}

/**
 * The person's own choice of design on this phone: classic › Settings › Appearance › "Try the new design", and the new
 * design's "Switch back to classic". Classic is the default (no choice made). It is kept here, in a small preference
 * file of its own, until the server keeps the person's design preference; [UiFlags] reads it, so every screen,
 * overlay and notice that asks the flags follows it.
 */
object DesignChoice {
    private const val FILE = "design_choice"
    private const val KEY = "design"

    private val state = MutableStateFlow<Design?>(null)

    /** The choice, null when none was made (the server's flags decide, classic by default). */
    val choice: StateFlow<Design?> = state.asStateFlow()

    val current: Design? get() = state.value

    /** Reads the stored choice (at app start). */
    fun load(context: Context) {
        state.value = Design.of(context.getSharedPreferences(FILE, Context.MODE_PRIVATE).getString(KEY, null))
    }

    /**
     * Applies a design the person chose: the server keeps it when it has the design switch (2.0.0-g; the choice on this
     * phone is then forgotten, the server's flags decide), else it is kept on this phone. Returns what happened, so
     * the screen can say "not open for your account yet".
     */
    suspend fun choose(context: Context, vm: MainViewModel, design: Design): MainViewModel.DesignAnswer {
        val answer = vm.chooseDesign(design.wire)
        when (answer) {
            MainViewModel.DesignAnswer.Taken -> set(context, null)
            MainViewModel.DesignAnswer.OnThisPhone -> set(context, design)
            MainViewModel.DesignAnswer.NotAvailable, MainViewModel.DesignAnswer.Failed -> Unit
        }
        return answer
    }

    /**
     * Classic › Settings › Appearance offers "Try the new design": not while this phone already shows it, and only while
     * the server says there is something to try ([server] `can_try`; an older server: always).
     */
    fun offersTry(server: com.echoledger.mobile.data.UiDesignDto?): Boolean = current != Design.V2 && (server?.canTry ?: true)

    /** Keeps [design] (null forgets the choice) and applies it at once. */
    fun set(context: Context, design: Design?) {
        context.getSharedPreferences(FILE, Context.MODE_PRIVATE).edit().apply {
            if (design == null) remove(KEY) else putString(KEY, design.wire)
        }.apply()
        state.value = design
    }
}

/** Which answers to `{"design": …}` mean the server is older than the design switch (2.0.0-g). */
object DesignServer {
    /** A 404 (no such route) or a 422 that is not `design_not_available` (the body was not understood). */
    fun tooOld(failure: com.echoledger.mobile.net.ApiException?): Boolean =
        failure != null && (failure.status == 404 || (failure.status == 422 && failure.code != "design_not_available"))
}
