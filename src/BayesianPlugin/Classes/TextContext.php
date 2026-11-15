<?php

    namespace BayesianPlugin\Classes;

    use FederationLib\Enums\NamedEntityType;

    class TextContext
    {
        /** A contact route: a link, a Telegram link, a handle or a phone number, also without a scheme */
        public const string CONTACT_ROUTE_PATTERN = '/(https?:\/\/|www\.|\bt\.me\/|(?<![\w.])@[a-z0-9_]{4,}|\+?\d[\d\s().-]{7,}\d)/iu';
        /** A word: two or more letters or numbers, at least one of them a letter */
        private const string WORD_PATTERN = '/(?=[\p{L}\p{N}]*\p{L})[\p{L}\p{M}\p{N}]{2,}/u';
        /** The characters of scripts written without spaces between words */
        private const string UNSPACED_SCRIPT_PATTERN = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u';
        /** The number of characters of a script written without spaces that count as one word */
        private const int UNSPACED_CHARACTERS_PER_WORD = 2;

        /**
         * Returns True if the text holds a contact route (a link, a Telegram link, a handle or a phone number)
         *
         * @param string $text The text
         * @return bool True if the text holds a contact route
         */
        public static function hasContactRoute(string $text): bool
        {
            return preg_match(self::CONTACT_ROUTE_PATTERN, $text) === 1;
        }

        /**
         * Returns True if the text holds enough words besides its named entities and contact routes to be classified
         * or learned, as configured by minimum_context_words (0 classifies every text)
         *
         * @param string $text The text
         * @param int|null $minimumWords Optional. The minimum number of words, the configured number if null
         * @return bool True if the text has enough context
         */
        public static function hasEnoughContext(string $text, ?int $minimumWords=null): bool
        {
            $minimumWords ??= Configuration::getMinimumContextWords();
            if($minimumWords <= 0)
            {
                return true;
            }

            return self::countContextWords($text) >= $minimumWords;
        }

        /**
         * Counts the words of the text besides its named entities and contact routes. A word of a script written
         * without spaces (eg; Chinese, Japanese or Thai) is counted for every two of its characters.
         *
         * @param string $text The text
         * @return int The number of words
         */
        public static function countContextWords(string $text): int
        {
            if(!mb_check_encoding($text, 'UTF-8'))
            {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }

            $remaining = self::removeRoutes($text);
            $unspaced = preg_match_all(self::UNSPACED_SCRIPT_PATTERN, $remaining);
            if($unspaced > 0)
            {
                $remaining = preg_replace(self::UNSPACED_SCRIPT_PATTERN, ' ', $remaining) ?? $remaining;
            }

            $words = preg_match_all(self::WORD_PATTERN, $remaining);
            return max(0, (int)$words) + (int)ceil(max(0, (int)$unspaced) / self::UNSPACED_CHARACTERS_PER_WORD);
        }

        /**
         * Replaces the named entities and contact routes of the text with spaces. Each one is removed up to the next
         * whitespace, so the path of a link without a scheme (eg; t.me/name, of which only t.me is a domain) is
         * removed along with it.
         *
         * @param string $text The text
         * @return string The text without its named entities and contact routes
         */
        private static function removeRoutes(string $text): string
        {
            $spans = [];
            foreach(NamedEntityType::extract($text) as $position)
            {
                $spans[] = [$position->getOffset(), $position->getOffset() + $position->getLength()];
            }

            if(preg_match_all(self::CONTACT_ROUTE_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) > 0)
            {
                foreach($matches[0] as [$match, $offset])
                {
                    $spans[] = [$offset, $offset + strlen($match)];
                }
            }

            $length = strlen($text);
            foreach($spans as [$start, $end])
            {
                // The rest of the route, up to the next whitespace
                $end += strcspn($text, " \t\r\n\f\v", min($end, $length));
                $text = substr_replace($text, str_repeat(' ', $end - $start), $start, $end - $start);
            }

            return $text;
        }
    }
