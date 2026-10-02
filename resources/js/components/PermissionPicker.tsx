import { Badge, Checkbox } from '@/components/geist';
import { useTranslator } from '@/lib/t';

/*
| Choosing what a role allows (frontend.md §3.4 D3, and §3.3 C6 for one person).
|
| Actions are shown grouped by **business area** - "Staff and permissions", "Store settings and
| tax" - because that is how the people using this think about them, not in the order the modules
| happened to declare them. The area comes from the permission catalog itself (stage 2b, P2), so a
| module that ships later appears under its own heading without this component changing.
|
| An action the author does not hold is shown but cannot be ticked: nobody hands out what they do
| not have. It is shown rather than hidden so that an admin can see the shape of the whole system
| and understand why something is not theirs to give (access.md §1.5) - and, as Geist asks of every
| disabled checkbox (frontend.md 1.10), the reason is its tooltip rather than a greyed box that
| reads as a bug.
|
| Each area is one group of checkboxes named by its heading, so a screen reader hears the area
| before each action in it. The area stays a <section> holding a list: the browser test finds the
| first action by that shape.
|
| Stores are not here at all. What a role allows and where a person may do it are two different
| questions, and the second is answered per staff member (§3.3 C6).
*/

export type PickerPermission = {
    name: string;
    label: string;
    group: string;
    storeFree: boolean;
    grantable: boolean;
};

export type PickerGroup = {
    key: string;
    label: string;
};

type Props = {
    permissions: PickerPermission[];
    groups: PickerGroup[];
    chosen: string[];
    onChange: (chosen: string[]) => void;
    /** Read-only: an admin looking at a role they may not change. */
    disabled?: boolean;
};

export function PermissionPicker({ permissions, groups, chosen, onChange, disabled = false }: Props) {
    const t = useTranslator();
    const held = new Set(chosen);

    function toggle(name: string, on: boolean) {
        onChange(on ? [...chosen, name] : chosen.filter((each) => each !== name));
    }

    function lockedBecause(permission: PickerPermission): string | undefined {
        if (disabled) {
            return t('access::roles.not_editable');
        }

        return permission.grantable ? undefined : t('access::roles.not_yours');
    }

    return (
        <div className="grid gap-6">
            {groups.map((group) => {
                const inGroup = permissions.filter((permission) => permission.group === group.key);

                // A business area no module has declared anything for yet simply is not shown,
                // rather than appearing as an empty heading.
                if (inGroup.length === 0) {
                    return null;
                }

                const chosenHere = inGroup.filter((permission) => held.has(permission.name)).length;
                const heading = `permission-group-${group.key}`;

                return (
                    <section key={group.key} className="material-base overflow-hidden">
                        <fieldset aria-labelledby={heading}>
                            <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                                <h3 id={heading} className="text-heading-14 text-ink">
                                    {group.label}
                                </h3>
                                {chosenHere > 0 ? (
                                    <Badge variant="blue-subtle" size="small">
                                        <span className="tw-figure">{t('access::roles.chosen_count', { count: chosenHere })}</span>
                                    </Badge>
                                ) : null}
                            </div>

                            <ul className="grid gap-0.5 p-2">
                                {inGroup.map((permission) => {
                                    const locked = lockedBecause(permission);

                                    return (
                                        <li
                                            key={permission.name}
                                            className={['rounded-sm px-2 py-2', locked === undefined ? 'hover:bg-surface-sunken' : ''].join(' ')}
                                        >
                                            <Checkbox
                                                id={`permission-${permission.name}`}
                                                checked={held.has(permission.name)}
                                                disabledReason={locked}
                                                onChange={(on) => toggle(permission.name, on)}
                                            >
                                                <span className="grid gap-0.5">
                                                    <span>{permission.label}</span>
                                                    {permission.storeFree ? (
                                                        <span className="text-copy-13 text-ink-muted">{t('access::roles.store_free')}</span>
                                                    ) : null}
                                                </span>
                                            </Checkbox>
                                        </li>
                                    );
                                })}
                            </ul>
                        </fieldset>
                    </section>
                );
            })}
        </div>
    );
}
