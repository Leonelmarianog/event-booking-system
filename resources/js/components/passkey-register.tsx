import { usePasskeyRegister } from '@laravel/passkeys/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    onSuccess: () => void;
};

export default function PasskeyRegistration({ onSuccess }: Props) {
    const [name, setName] = useState(() => {
        const ua = navigator.userAgent;

        const browser = [
            { pattern: /Edg|Edge/, name: 'Edge' },
            { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
            { pattern: /Firefox|FxiOS/, name: 'Firefox' },
            { pattern: /Chrome|CriOS/, name: 'Chrome' },
            { pattern: /Safari/, name: 'Safari' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        const os = [
            { pattern: /iPhone/, name: 'iPhone' },
            { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
            { pattern: /Android/, name: 'Android' },
            { pattern: /Mac/, name: 'Mac' },
            { pattern: /Windows/, name: 'Windows' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        return [browser, os].filter(Boolean).join(' on ') || '';
    });

    const [showForm, setShowForm] = useState(false);
    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setName('');
            setShowForm(false);
            onSuccess();
        },
    });

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!name.trim()) {
            return;
        }

        await register(name);
    };

    const handleCancel = () => {
        setShowForm(false);
        setName('');
    };

    if (!isSupported) {
        return (
            <p className="text-muted-foreground">
                Passkeys are not supported in this browser.
            </p>
        );
    }

    if (!showForm) {
        return (
            <Button
                variant="outline"
                size="md"
                onClick={() => setShowForm(true)}
            >
                <Plus aria-hidden="true" />
                Add passkey
            </Button>
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="grid max-w-[520px] gap-2 border border-border p-5"
        >
            <FieldLabel htmlFor="passkey-name">Passkey name</FieldLabel>
            <Input
                id="passkey-name"
                type="text"
                size="lg"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                autoFocus
                aria-describedby="passkey-name-hint"
            />
            <p id="passkey-name-hint" className="text-sm text-muted-foreground">
                A name helps you find this passkey later.
            </p>

            {error && <InputError message={error} />}

            <div className="mt-3 flex flex-wrap gap-3">
                <Button
                    type="submit"
                    size="md"
                    disabled={isLoading || !name.trim()}
                >
                    {isLoading ? 'Adding...' : 'Add passkey'}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="md"
                    onClick={handleCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
