import type { Metadata } from "next";
import StorageClient from "./StorageClient";

export const metadata: Metadata = { title: "Storage" };

export default function StoragePage() {
    return <StorageClient />;
}
