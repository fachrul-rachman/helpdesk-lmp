import { useId } from 'react';

import { Input } from '../ui/input';
import { Label } from '../ui/label';

type Props = {
    site: string;
    zone: string;
    lotNumber: string;
    onSiteChange: (value: string) => void;
    onZoneChange: (value: string) => void;
    onLotNumberChange: (value: string) => void;
    disabled?: boolean;
};

export function TicketLocationFields({
    site,
    zone,
    lotNumber,
    onSiteChange,
    onZoneChange,
    onLotNumberChange,
    disabled,
}: Props) {
    const fieldId = useId();

    return (
        <div className="grid gap-4 sm:grid-cols-3">
            <div className="space-y-2">
                <Label htmlFor={`${fieldId}-site`}>Site</Label>
                <Input id={`${fieldId}-site`} value={site} onChange={(e) => onSiteChange(e.target.value)} disabled={disabled} maxLength={255} />
            </div>
            <div className="space-y-2">
                <Label htmlFor={`${fieldId}-zone`}>Zone</Label>
                <Input id={`${fieldId}-zone`} value={zone} onChange={(e) => onZoneChange(e.target.value)} disabled={disabled} maxLength={255} />
            </div>
            <div className="space-y-2">
                <Label htmlFor={`${fieldId}-lot-number`}>Lot Number</Label>
                <Input id={`${fieldId}-lot-number`} value={lotNumber} onChange={(e) => onLotNumberChange(e.target.value)} disabled={disabled} maxLength={255} />
            </div>
        </div>
    );
}
