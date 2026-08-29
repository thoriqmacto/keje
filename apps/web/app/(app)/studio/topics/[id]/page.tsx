import TopicDetailClient from "./TopicDetailClient";

export const metadata = { title: "Topic" };

export default async function TopicDetailPage({
    params,
}: {
    params: Promise<{ id: string }>;
}) {
    const { id } = await params;

    return <TopicDetailClient topicId={id} />;
}
