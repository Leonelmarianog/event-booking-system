import { Head } from '@inertiajs/react';
import { SettingsSection } from '@/components/settings-section';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

const options: {
    value: Appearance;
    label: string;
    background: string;
    text: string;
    dot: string;
}[] = [
    {
        value: 'light',
        label: 'Light',
        background: '#f4f2ec',
        text: '#0d0d0e',
        dot: '#0d0d0e',
    },
    {
        value: 'dark',
        label: 'Dark',
        background: '#0d0d0e',
        text: '#f2f0eb',
        dot: '#e8ff47',
    },
    {
        value: 'system',
        label: 'System',
        background: 'linear-gradient(90deg, #f4f2ec 50%, #0d0d0e 50%)',
        text: '#75746d',
        dot: '#e8ff47',
    },
];

export default function AppearancePage() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <>
            <Head title="Appearance settings" />

            <SettingsSection
                id="appearance-heading"
                title="Appearance"
                description="Choose how Gather looks on this device."
                className="max-w-[820px]"
            >
                <fieldset className="m-0 min-w-0 border-0 p-0">
                    <legend className="sr-only">Appearance</legend>
                    <div className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-5">
                        {options.map((option) => {
                            const checked = appearance === option.value;

                            return (
                                <label
                                    key={option.value}
                                    className={cn(
                                        'relative block cursor-pointer border-2 bg-field focus-within:outline-3 focus-within:outline-offset-3 focus-within:outline-ring',
                                        checked
                                            ? 'border-foreground dark:border-lime'
                                            : 'border-border',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="appearance"
                                        value={option.value}
                                        checked={checked}
                                        onChange={() =>
                                            updateAppearance(option.value)
                                        }
                                        className="sr-only"
                                    />
                                    <span
                                        aria-hidden="true"
                                        className="block h-[140px] p-[18px]"
                                        style={{
                                            background: option.background,
                                        }}
                                    >
                                        <span
                                            className="block font-display text-[22px] uppercase"
                                            style={{ color: option.text }}
                                        >
                                            Gather
                                            <span style={{ color: option.dot }}>
                                                .
                                            </span>
                                        </span>
                                        <span
                                            className="mt-3.5 block h-2.5 w-[70%] opacity-85"
                                            style={{ background: option.text }}
                                        />
                                        <span
                                            className="mt-2 block h-2.5 w-[45%] opacity-40"
                                            style={{ background: option.text }}
                                        />
                                        <span className="mt-4 inline-block h-[22px] w-18 bg-lime" />
                                    </span>
                                    <span className="flex items-center justify-between px-[18px] py-3.5 text-sm font-bold tracking-[0.1em] uppercase">
                                        {option.label}
                                        {checked && (
                                            <span
                                                aria-hidden="true"
                                                className="text-xs dark:text-lime"
                                            >
                                                Selected
                                            </span>
                                        )}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                </fieldset>
            </SettingsSection>
        </>
    );
}
