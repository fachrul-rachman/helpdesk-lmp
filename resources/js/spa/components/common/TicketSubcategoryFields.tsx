import { useEffect, useId, useState } from 'react';

import { api } from '../../lib/axios';
import type { TicketSubcategory, TicketSubcategoryOptions } from '../../types/ticket';
import { Label } from '../ui/label';

type Props = {
    divisionId?: string | null;
    globalValue: string;
    divisionValue: string;
    currentGlobal?: TicketSubcategory | null;
    currentDivision?: TicketSubcategory | null;
    onGlobalChange: (value: string) => void;
    onDivisionChange: (value: string) => void;
    disabled?: boolean;
};

export function TicketSubcategoryFields({
    divisionId,
    globalValue,
    divisionValue,
    currentGlobal,
    currentDivision,
    onGlobalChange,
    onDivisionChange,
    disabled,
}: Props) {
    const fieldId = useId();
    const [options, setOptions] = useState<TicketSubcategoryOptions>({
        global: [],
        division: [],
    });

    useEffect(() => {
        let active = true;
        api.get<{ data: TicketSubcategoryOptions }>('/api/ticket-subcategories', {
            params: divisionId ? { division_id: divisionId } : undefined,
        })
            .then((response) => {
                if (active) {
                    setOptions(response.data.data);
                }
            })
            .catch(() => {
                if (active) {
                    setOptions({ global: [], division: [] });
                }
            });

        return () => {
            active = false;
        };
    }, [divisionId]);

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
                <Label htmlFor={`${fieldId}-global`}>Subkategori Global</Label>
                <select
                    id={`${fieldId}-global`}
                    className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                    value={globalValue}
                    onChange={(e) => onGlobalChange(e.target.value)}
                    disabled={disabled}
                >
                    <option value="">Belum dipilih</option>
                    {currentGlobal && !options.global.some((option) => option.id === currentGlobal.id) ? (
                        <option value={currentGlobal.id}>{currentGlobal.name} (Nonaktif)</option>
                    ) : null}
                    {options.global.map((option) => (
                        <option key={option.id} value={option.id}>
                            {option.name}
                        </option>
                    ))}
                </select>
            </div>
            <div className="space-y-2">
                <Label htmlFor={`${fieldId}-division`}>Subkategori Divisi</Label>
                <select
                    id={`${fieldId}-division`}
                    className="h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                    value={divisionValue}
                    onChange={(e) => onDivisionChange(e.target.value)}
                    disabled={disabled || !divisionId}
                >
                    <option value="">Belum dipilih</option>
                    {currentDivision && !options.division.some((option) => option.id === currentDivision.id) ? (
                        <option value={currentDivision.id}>{currentDivision.name} (Nonaktif)</option>
                    ) : null}
                    {options.division.map((option) => (
                        <option key={option.id} value={option.id}>
                            {option.name}
                        </option>
                    ))}
                </select>
            </div>
        </div>
    );
}
