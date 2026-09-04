import type { Metadata } from 'next';

/**
 * Federation (fork): one <title> per member page, page name first and the
 * site after, in the page's language (docs/ACCESSIBILITY.md, B9). Pages
 * export `generateMetadata` and pass their key under `titles` in the
 * federation namespace of each locale.
 *
 * The locale files are imported directly: next-translate's `getT` does not
 * resolve the namespace inside `generateMetadata` in this setup (it answered
 * with the raw keys), and a title must never show a key.
 */
type FederationDictionary = {
    nav: { site: string };
    titles: Record<string, string>;
};

const dictionaries: Record<string, () => Promise<FederationDictionary>> = {
    en: () =>
        import('../../../locales/en/federation.json').then(
            (m) => m.default as unknown as FederationDictionary,
        ),
    de: () =>
        import('../../../locales/de/federation.json').then(
            (m) => m.default as unknown as FederationDictionary,
        ),
};

export async function memberMetadata(
    lang: string,
    key: string,
): Promise<Metadata> {
    const load = dictionaries[lang] ?? dictionaries.en;
    const dictionary = await load();

    return { title: `${dictionary.titles[key]} · ${dictionary.nav.site}` };
}
