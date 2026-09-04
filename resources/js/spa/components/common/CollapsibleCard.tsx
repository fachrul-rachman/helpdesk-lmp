import { ChevronDown } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';

import { cn } from '../../lib/utils';

type CollapsibleCardProps = {
    title: string;
    children: ReactNode;
    defaultOpen?: boolean;
};

export function CollapsibleCard({
    title,
    children,
    defaultOpen = false,
}: CollapsibleCardProps) {
    const [isOpen, setIsOpen] = useState(defaultOpen);
    const contentId = useId();

    return (
        <section className="rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <button
                type="button"
                className={cn(
                    'flex w-full items-center justify-between gap-3 px-4 py-3 text-left',
                    isOpen && 'border-b border-slate-200',
                )}
                aria-expanded={isOpen}
                aria-controls={contentId}
                onClick={() => setIsOpen((value) => !value)}
            >
                <span className="text-sm font-semibold text-slate-900">
                    {title}
                </span>
                <ChevronDown
                    aria-hidden="true"
                    className={cn(
                        'h-4 w-4 shrink-0 text-slate-500 transition-transform',
                        !isOpen && '-rotate-90',
                    )}
                />
            </button>
            {isOpen ? (
                <div id={contentId} className="p-4">
                    {children}
                </div>
            ) : null}
        </section>
    );
}
