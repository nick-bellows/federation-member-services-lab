import Title from './Navigation/Title';

export default function TitleBar() {
    return (
        <div className="col-span-1 flex items-center py-4">
            <Title className="flex-1" />
        </div>
    );
}
