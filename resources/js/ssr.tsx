import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import ReactDOMServer from 'react-dom/server';
import { Providers, firstDirection } from '@/components/Providers';

/*
| The server half of every page (frontend.md §1.3). Every page is rendered here first, admin
| included, by the Node process `php artisan inertia:start-ssr` runs.
|
| Nothing rendered through here may touch `window` or `document`: there is neither. A component that
| needs the browser does its work in an effect, which runs only after hydration.
*/

void createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        resolve: (name) => {
            const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
            const component = pages[`./pages/${name}.tsx`];

            if (component === undefined) {
                throw new Error(`No page component for "${name}".`);
            }

            return component as never;
        },
        // The same providers as the browser's entry, so the server renders what hydration expects.
        setup: ({ App, props }) => (
            <Providers direction={firstDirection(props.initialPage.props)}>
                <App {...props} />
            </Providers>
        ),
    }),
);
