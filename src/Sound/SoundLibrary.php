<?php

declare(strict_types=1);

namespace App\Sound;

/**
 * The sounds the application ships with.
 *
 * The list is the one the original application plays, down to the names and
 * what each one shows, so a message written for one installation reads the
 * same on the other. It is a fixed list: a sound that is not named here is not
 * a sound at all, and the message stays text.
 */
final class SoundLibrary
{
    /**
     * @var list<Sound>|null
     */
    private ?array $sounds = null;

    /**
     * The sound a message asks for, or null when the name is not one of them.
     */
    public function find(string $name): ?Sound
    {
        foreach ($this->all() as $sound) {
            if ($sound->name === $name) {
                return $sound;
            }
        }

        return null;
    }

    /**
     * The names of every sound, sorted, which is what the list of them shows.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = array_map(static fn (Sound $sound): string => $sound->name, $this->all());
        sort($names);

        return $names;
    }

    /**
     * @return list<Sound>
     */
    private function all(): array
    {
        return $this->sounds ??= [
            Sound::withImage('56k', '56k.webp', 79, 33),
            Sound::withText('ballmer', 'developers!'),
            Sound::withText('bell', '🔔'),
            Sound::withText('bezos', '😆💭'),
            Sound::withText('bueller', 'anyone?'),
            Sound::withText('butts', '👐 🚬'),
            Sound::withImage('clowntown', 'clowntown.webp', 210, 150),
            Sound::withText('cottoneyejoe', '🎶🙉🎶 '),
            Sound::withText('crickets', 'hears crickets chirping'),
            Sound::withImage('curb', 'curb.webp', 150, 101),
            Sound::withText('dadgummit', 'dad gummit!! 🎣'),
            Sound::withImage('dangerzone', 'dangerzone.webp', 157, 32),
            Sound::withText('danielsan', '🎆 🏆 🎆'),
            Sound::withImage('deeper', 'top.webp', 188, 80),
            Sound::withImage('donotwant', 'donotwant.webp', 150, 150),
            Sound::withImage('drama', 'drama.webp', 300, 16),
            Sound::withText('flawless', '#flawless'),
            Sound::withText('glados', '🤖💢'),
            Sound::withText('gogogo', 'Go, go, go!'),
            Sound::withImage('greatjob', 'greatjob.webp', 79, 16),
            Sound::withText('greyjoy', '😖🎺'),
            Sound::withText('guarantee', 'guarantees it 👌'),
            Sound::withText('heygirl', '✨💁✨'),
            Sound::withText('honk', 'HONK'),
            Sound::withText('horn', '🐶 ✂️ 🐱'),
            Sound::withText('horror', '💀 💀 💀 💀 💀 💀 💀'),
            Sound::withText('inconceivable', "doesn't think it means what you think it means…"),
            Sound::withText('letitgo', '❄️👩❄️⛄️❄️'),
            Sound::withText('live', 'is DOING IT LIVE'),
            Sound::withImage('loggins', 'loggins.webp', 200, 151),
            Sound::withText('makeitso', 'make it so 👉'),
            Sound::withText('noooo', '👸💀😒'),
            Sound::withImage('nyan', 'nyan.webp', 36, 15),
            Sound::withText('ohmy', 'raises an eyebrow 😏'),
            Sound::withText('ohyeah', "isn't playing by the rules"),
            Sound::withImage('pushit', 'pushit.webp', 104, 15),
            Sound::withText('rimshot', 'plays a rimshot'),
            Sound::withText('rollout', 'is rolling out 🚗'),
            Sound::withImage('rumble', 'rumble.webp', 220, 150),
            Sound::withText('sax', '🌇🎷🎶'),
            Sound::withText('secret', 'found a secret area 🔑'),
            Sound::withText('sexyback', '🔞'),
            Sound::withText('story', 'and now you know…'),
            Sound::withText('tada', 'plays a fanfare 🎏'),
            Sound::withText('tmyk', '✨ ⭐️ The More You Know ✨ ⭐️'),
            Sound::withText('totes', '😁👍'),
            Sound::withText('trololo', 'трололо'),
            Sound::withText('trombone', 'plays a sad trombone'),
            Sound::withText('unix', 'knows this 💻'),
            Sound::withText('vuvuzela', '======<() ~ ♪ ~♫'),
            Sound::withImage('what', 'what.webp', 100, 131),
            Sound::withText('whoomp', '👏‼️😎'),
            Sound::withText('wups', 'wups!'),
            Sound::withImage('yay', 'yay.webp', 103, 50),
            Sound::withImage('yeah', 'yeah.webp', 104, 15),
            Sound::withText('yodel', '📣🗻🙉'),
        ];
    }
}
