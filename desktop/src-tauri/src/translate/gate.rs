//! Which 100 ms frames go to Soniox: Soniox bills the time a stream is open, so audio is sent only while someone
//! speaks (an energy gate over a tracked noise floor, 300 ms of pre-roll, 600 ms of hang-over). With game effects or
//! music under the voice, a frame must also cross zero at a voiced-speech rate. Same rules as the phone.

use std::collections::VecDeque;

pub const FRAME_SAMPLES: usize = 1_600; // 100 ms at 16 kHz
pub const PRE_ROLL_FRAMES: usize = 3;
pub const HANG_FRAMES: u32 = 6;

#[derive(Debug, Default)]
pub struct GateResult {
    pub frames: Vec<Vec<i16>>,
    pub speaking: bool,
    pub onset: bool,
}

pub struct SpeechGate {
    mixed: bool,
    pre_roll: VecDeque<Vec<i16>>,
    noise_floor: f64,
    hang: u32,
    pub speaking: bool,
    pub quiet_frames: u32,
}

impl SpeechGate {
    pub fn new(mixed: bool) -> Self {
        Self {
            mixed,
            pre_roll: VecDeque::new(),
            noise_floor: 150.0,
            hang: 0,
            speaking: false,
            quiet_frames: 0,
        }
    }

    pub fn offer(&mut self, frame: Vec<i16>) -> GateResult {
        let level = rms(&frame);
        let loud = level > (self.noise_floor * 2.5).max(250.0);
        let voiced = loud && (!self.mixed || looks_voiced(&frame));
        self.noise_floor = if level < self.noise_floor {
            self.noise_floor * 0.8 + level * 0.2
        } else {
            self.noise_floor * 0.995 + level * 0.005
        };
        self.noise_floor = self.noise_floor.clamp(20.0, 4_000.0);
        if voiced {
            self.quiet_frames = 0;
            self.hang = HANG_FRAMES;
            if self.speaking {
                GateResult {
                    frames: vec![frame],
                    speaking: true,
                    onset: false,
                }
            } else {
                self.speaking = true;
                let mut frames: Vec<Vec<i16>> = self.pre_roll.drain(..).collect();
                frames.push(frame);
                GateResult {
                    frames,
                    speaking: true,
                    onset: true,
                }
            }
        } else {
            self.quiet_frames += 1;
            if self.speaking && self.hang > 0 {
                self.hang -= 1;
                GateResult {
                    frames: vec![frame],
                    speaking: true,
                    onset: false,
                }
            } else {
                self.speaking = false;
                self.pre_roll.push_back(frame);
                while self.pre_roll.len() > PRE_ROLL_FRAMES {
                    self.pre_roll.pop_front();
                }
                GateResult::default()
            }
        }
    }
}

pub fn rms(frame: &[i16]) -> f64 {
    if frame.is_empty() {
        return 0.0;
    }
    (frame
        .iter()
        .map(|&s| f64::from(s) * f64::from(s))
        .sum::<f64>()
        / frame.len() as f64)
        .sqrt()
}

/// Voiced speech crosses zero roughly 300-3200 times a second at 16 kHz; hiss far more, rumble far less.
pub fn looks_voiced(frame: &[i16]) -> bool {
    if frame.len() < 2 {
        return false;
    }
    let crossings = frame
        .windows(2)
        .filter(|w| (w[0] >= 0) != (w[1] >= 0))
        .count();
    let per_second = crossings as f64 * 16_000.0 / frame.len() as f64;
    (300.0..=3_200.0).contains(&per_second)
}

/// f32 samples (any rate) to 16-bit at 16 kHz, linear: the WASAPI stream is already 16 kHz, so this is mostly a copy.
pub fn to_pcm16(samples: &[f32], from_rate: u32) -> Vec<i16> {
    let convert = |s: f32| (s.clamp(-1.0, 1.0) * 32_767.0) as i16;
    if from_rate == 16_000 || from_rate == 0 {
        return samples.iter().copied().map(convert).collect();
    }
    let len = (samples.len() as u64 * 16_000 / u64::from(from_rate)) as usize;
    (0..len)
        .map(|i| {
            let pos = i as f64 * f64::from(from_rate) / 16_000.0;
            let a = (pos as usize).min(samples.len() - 1);
            let b = (a + 1).min(samples.len() - 1);
            let f = (pos - a as f64) as f32;
            convert(samples[a] * (1.0 - f) + samples[b] * f)
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tone(level: i16, period: usize) -> Vec<i16> {
        (0..FRAME_SAMPLES)
            .map(|i| {
                if i % period < period / 2 {
                    level
                } else {
                    -level
                }
            })
            .collect()
    }

    #[test]
    fn silence_sends_nothing_and_speech_starts_with_its_pre_roll() {
        let mut gate = SpeechGate::new(false);
        for _ in 0..10 {
            assert!(gate.offer(vec![0; FRAME_SAMPLES]).frames.is_empty());
        }
        let onset = gate.offer(tone(4_000, 16));
        assert!(onset.onset);
        assert_eq!(onset.frames.len(), PRE_ROLL_FRAMES + 1);
        for _ in 0..HANG_FRAMES {
            assert!(gate.offer(vec![0; FRAME_SAMPLES]).speaking);
        }
        assert!(!gate.offer(vec![0; FRAME_SAMPLES]).speaking);
    }

    #[test]
    fn with_game_sounds_hiss_and_rumble_stay_out() {
        let mut gate = SpeechGate::new(true);
        assert!(!gate.offer(tone(5_000, 2)).speaking);
        assert!(!gate.offer(tone(5_000, 400)).speaking);
        assert!(gate.offer(tone(5_000, 16)).speaking);
    }

    #[test]
    fn samples_become_16_bit_at_16_khz() {
        assert_eq!(to_pcm16(&[0.5, -1.5], 16_000), vec![16_383, -32_767]);
        assert_eq!(to_pcm16(&vec![0.0; 48_000], 48_000).len(), 16_000);
    }
}
