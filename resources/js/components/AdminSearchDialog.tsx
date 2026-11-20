import { defaultFilter } from 'cmdk';
import { router, usePage } from '@inertiajs/react';
import { MenuIcon } from '@/components/MenuIcon';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useTranslator } from '@/lib/t';
import { useReturnFocus } from '@/lib/use-return-focus';
import type { SharedProps } from '@/types/page';

/*
| The dialog behind the header search (flags-2026-11.md S7): the person's own menu - already on the page, so
| nothing is read from the server - grouped as the sidebar groups it, an entry with its icon as the sidebar
| draws it. "Coming soon" entries are left out. Its own chunk; see AdminSearch.
|
| Made from shadcn's own parts the way its CommandDialog is (Dialog, DialogContent, Command), because CommandDialog
| hands nothing to the content: the panel's dialogs draw no Close button of their own (frontend.md §1.11, edit 5)
| and give focus back to what opened them (§6, useReturnFocus). Each entry's value is its own (`module.key`): two
| entries share a name - Access's and Loyalty's "Customers" - and cmdk lights and opens by value, so the second
| could not be reached. The typing still matches the entry's name, through its keywords (as RoleChoice does).
*/

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export function AdminSearchDialog({ open, onOpenChange }: Props) {
    const { menu } = usePage<SharedProps>().props;
    const t = useTranslator();
    const returnFocus = useReturnFocus(open);
    const title = t('admin.search.title');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-hidden p-0" showCloseButton={false} onCloseAutoFocus={returnFocus}>
                <DialogHeader className="sr-only">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{t('admin.search.button')}</DialogDescription>
                </DialogHeader>
                <Command
                    label={title}
                    filter={(_value, search, keywords) => defaultFilter((keywords ?? []).join(' '), search)}
                    data-test="admin-search-command"
                    className="**:data-[slot=command-input-wrapper]:h-12 [&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:font-medium [&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-group]]:px-2 [&_[cmdk-group]:not([hidden])_~[cmdk-group]]:pt-0 [&_[cmdk-input-wrapper]_svg]:h-5 [&_[cmdk-input-wrapper]_svg]:w-5 [&_[cmdk-input]]:h-12 [&_[cmdk-item]]:px-2 [&_[cmdk-item]]:py-3 [&_[cmdk-item]_svg]:h-5 [&_[cmdk-item]_svg]:w-5"
                >
                    <CommandInput placeholder={t('admin.search.button')} data-test="admin-search-input" />
                    <CommandList label={title}>
                        <CommandEmpty>{t('admin.search.empty')}</CommandEmpty>
                        {menu.map((group) => {
                            const entries = group.entries.filter((entry) => !entry.comingSoon);

                            if (entries.length === 0) {
                                return null;
                            }

                            return (
                                <CommandGroup key={group.key} heading={group.label}>
                                    {entries.map((entry) => (
                                        <CommandItem
                                            key={`${entry.module}.${entry.key}`}
                                            value={`${entry.module}.${entry.key}`}
                                            keywords={[entry.label]}
                                            data-test={`admin-search-${entry.module}-${entry.key}`}
                                            onSelect={() => {
                                                onOpenChange(false);
                                                router.visit(entry.href);
                                            }}
                                        >
                                            <MenuIcon name={group.entries.length === 1 ? group.key : entry.icon} />
                                            {entry.label}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            );
                        })}
                    </CommandList>
                </Command>
            </DialogContent>
        </Dialog>
    );
}
