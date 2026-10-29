param([Parameter(Mandatory)][string[]]$Paths)
Add-Type -TypeDefinition @'
using System;
using System.IO;
public static class WavStats {
    public static string Describe(string path) {
        byte[] b = File.ReadAllBytes(path);
        int ch = BitConverter.ToInt16(b, 22);
        int rate = BitConverter.ToInt32(b, 24);
        int pos = 12, data = -1, len = 0;
        while (pos + 8 <= b.Length) {
            string id = System.Text.Encoding.ASCII.GetString(b, pos, 4);
            int size = BitConverter.ToInt32(b, pos + 4);
            if (id == "data") { data = pos + 8; len = Math.Min(size, b.Length - data); break; }
            pos += 8 + size;
        }
        if (data < 0) return "no data chunk";
        int frames = len / (2 * ch);
        double[] sum = new double[ch]; int[] peak = new int[ch]; double[] active = new double[ch];
        int slot = rate / 50; double[] slotSum = new double[ch]; int inSlot = 0;
        double cross = 0, e0 = 0, e1 = 0;
        for (int i = 0; i < frames; i++) {
            for (int c = 0; c < ch; c++) {
                int v = BitConverter.ToInt16(b, data + (i * ch + c) * 2);
                sum[c] += (double)v * v; slotSum[c] += (double)v * v;
                int a = Math.Abs(v); if (a > peak[c]) peak[c] = a;
            }
            if (ch == 2) {
                double l = BitConverter.ToInt16(b, data + i * 4), r = BitConverter.ToInt16(b, data + i * 4 + 2);
                cross += l * r; e0 += l * l; e1 += r * r;
            }
            if (++inSlot == slot) {
                for (int c = 0; c < ch; c++) { if (Math.Sqrt(slotSum[c] / slot) >= 328) active[c] += 0.02; slotSum[c] = 0; }
                inSlot = 0;
            }
        }
        var sb = new System.Text.StringBuilder();
        sb.AppendFormat("{0}: {1} ch, {2} Hz, {3:F1} s", Path.GetFileName(path), ch, rate, frames / (double)rate);
        string[] names = ch == 2 ? new[] { "L me", "R other" } : new[] { "mono" };
        for (int c = 0; c < ch; c++) {
            double rms = Math.Sqrt(sum[c] / Math.Max(frames, 1));
            sb.AppendFormat(" | {0}: rms {1:F1} dBFS, peak {2:F1} dBFS, speech {3:F1} s", names[c],
                rms <= 0 ? -120 : 20 * Math.Log10(rms / 32768.0), peak[c] <= 0 ? -120 : 20 * Math.Log10(peak[c] / 32768.0), active[c]);
        }
        if (ch == 2) sb.AppendFormat(" | L/R correlation {0:F2}", (e0 > 0 && e1 > 0) ? cross / Math.Sqrt(e0 * e1) : 0);
        return sb.ToString();
    }
}
'@
foreach ($p in $Paths) { [WavStats]::Describe((Resolve-Path $p).Path) }
