import React from "react";

export default function Layout({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="min-h-screen bg-gray-100">
            {/* Header */}
            <header className="bg-[#840028] text-white p-4 shadow-md">
                <h1 className="text-xl font-bold">{title}</h1>
            </header>

            {/* Content */}
            <main className="max-w-6xl mx-auto mt-6 bg-white p-6 rounded-lg shadow">
                {children}
            </main>
        </div>
    );
}
