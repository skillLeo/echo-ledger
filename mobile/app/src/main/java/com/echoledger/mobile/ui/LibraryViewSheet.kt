package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.Density
import com.echoledger.mobile.core.GroupBy
import com.echoledger.mobile.core.LibrarySort
import com.echoledger.mobile.core.LibraryViewChoice

/**
 * The Library's View sheet (since v1.6, Phase 1C D4): Group by, Sort by and Density, each one tap, remembered for this
 * view on this phone. The desktop and the console have the same choices in their toolbar menus. [onChoose] gets the
 * change, applied to the stored choice, so two quick taps both count.
 */
@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun LibraryViewSheet(choice: LibraryViewChoice, onChoose: ((LibraryViewChoice) -> LibraryViewChoice) -> Unit, onDismiss: () -> Unit) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = c.surface) {
        Column(
            Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = Space.screen).padding(bottom = Space.s5),
            verticalArrangement = Arrangement.spacedBy(Space.s3)
        ) {
            Text("View", style = type.titleM, color = c.text, modifier = Modifier.semantics { heading() })
            Section("Group by") {
                GroupBy.entries.forEach { g ->
                    FilterChip(
                        selected = choice.groupBy == g,
                        onClick = { onChoose { it.copy(group = g.key) } },
                        label = { Text(g.label) }
                    )
                }
            }
            Section("Sort by") {
                LibrarySort.entries.forEach { s ->
                    FilterChip(
                        selected = choice.sortBy == s,
                        onClick = { onChoose { it.copy(sort = s.key) } },
                        label = { Text(s.label) }
                    )
                }
            }
            Section("Density") {
                Density.entries.forEach { d ->
                    FilterChip(
                        selected = choice.densityOf == d,
                        onClick = { onChoose { it.copy(density = d.key) } },
                        label = { Text(d.label) }
                    )
                }
            }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun Section(title: String, chips: @Composable () -> Unit) {
    val c = LocalEchoColors.current
    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(title, style = LocalEchoType.current.labelM, color = c.textMuted, modifier = Modifier.semantics { heading() })
        FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) { chips() }
    }
}
